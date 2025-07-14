from rdflib import Graph
import os, json, tempfile, shutil, subprocess, hashlib

class QuillPathNotFoundException(Exception):
	pass

class InvalidQuillException(Exception):
	pass

class QuillRequiredFileMissing(Exception):
	pass

class QuillCommandsMissing(Exception):
	pass

class QuillNotPrepared(Exception):
	pass

class QuillHasNoImportFile(Exception):
	pass

class QuillHasNoImportCommands(Exception):
	pass

class Quill():

	def __init__(self, source_path, core_settings=None):

		self.source_path = source_path
		self.settings_file = os.path.join(source_path, 'publish.json')
		self.hopper = tempfile.TemporaryDirectory(prefix="hedgehog_")
		self.prepared = False
		self.stdout = []
		self.stderr = []
		self.progress = None
		self.result = None

		if not os.path.exists(self.settings_file):
			raise QuillPathNotFoundException()
		self.settings = {}
		if isinstance(core_settings, dict):
			self.settings = self.core_settings.copy()
		with open(self.settings_file) as fp:
			settings_data = json.load(fp)
		if not isinstance(settings_data, dict):
			raise InvalidQuillException()
		for k, v in settings_data.items():
			self.settings[k] = v
		if not 'commands' in self.settings:
			raise QuillCommandsMissing()
		shutil.copytree(self.source_path, self.hopper.name, dirs_exist_ok=True)
		if len(self.missing_files()) > 0:
			raise QuillRequiredFileMissing()
		if 'prepare' in self.settings['commands']:
			if len(self.settings['commands']['prepare']) == 0:
				self.prepared = True
		else:
			self.prepared = True

	def prepare(self):
		if self.prepared:
			return
		for cmd in self.settings['commands']['prepare']:
			try:
				ret = subprocess.run(cmd, capture_output=True, shell=True, cwd=self.hopper.name, check=True)
			except subprocess.CalledProcessError as e:
				e.add_note("\nQuill error output follows...\n\n" + e.stderr.decode('utf8'))
				raise e
			stdout = ret.stdout.decode('utf8').strip()
			stderr = ret.stderr.decode('utf8').strip()
			if len(stdout) > 0:
				self.stdout.append(stdout)
			if len(stderr) > 0:
				self.stderr.append(stderr)
		self.prepared = True
		return self.stdout

	def run(self):
		if self.result:
			return self.result
		if not self.prepared:
			raise QuillNotPrepared()
		if not 'properties' in self.settings:
			raise QuillHasNoImportFile
		if not 'import_file' in self.settings['properties']:
			raise QuillHasNoImportFile
		if not 'import' in self.settings['commands']:
			raise QuillHasNoImportCommands
		for cmd in self.settings['commands']['import']:
			try:
				ret = subprocess.run(cmd, capture_output=True, shell=True, cwd=self.hopper.name, check=True)
			except subprocess.CalledProcessError as e:
				e.add_note("\nQuill error output follows...\n\n" + e.stderr.decode('utf8'))
				raise e
			stdout = ret.stdout.decode('utf8').strip()
			stderr = ret.stderr.decode('utf8').strip()
			if len(stdout) > 0:
				self.stdout.append(stdout)
			if len(stderr) > 0:
				self.stderr.append(stderr)
		import_file = os.path.join(self.hopper.name, self.settings['properties']['import_file'])
		if not os.path.exists(import_file):
			raise QuillHasNoImportFile

		g = Graph()
		g.parse(import_file)
		self.result = g
		return g

	def hashes(self):
		if not self.prepared:
			raise QuillNotPrepared()
		ret = {}
		for f in os.listdir(self.hopper.name):
			f_path = os.path.join(self.hopper.name, f)
			with open(f_path, 'rb', buffering=0) as fp:
				ret[f] = hashlib.file_digest(fp, 'sha1').hexdigest()
		return ret

	def missing_files(self):
		if not 'files' in self.settings:
			return []
		ret = []
		for f in self.settings['files']:
			path = os.path.join(self.hopper.name, f)
			if os.path.exists(path):
				continue
			ret.append(f)
		return ret

	def __del__(self):

		temp_path = self.hopper.name
		del(self.hopper)
		if os.path.exists(temp_path):
			shutil.rmtree(temp_path, ignore_errors=True)
