from rdflib import Graph, URIRef, Literal
from rdflib.namespace import FOAF, RDF, DC, DCTERMS, XSD
import os, json, tempfile, shutil, subprocess, hashlib, requests, sys, datetime, pytz

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

class ToolsDirectoryNotDefined(Exception):
	pass

class IncomingDirectoryNotDefined(Exception):
	pass

class ToolNotFound(Exception):
	pass

class IncomingFileNotFound(Exception):
	pass

class Quill():

	@property
	def uri(self):
		if 'uri' in self.settings:
			return self.settings['uri']
		return self.settings['rdf_base'] + os.path.split(self.source_path)[1]

	def __str__(self):
		return self.uri

	def __init__(self, source_path, core_settings=None):

		self.source_path = source_path
		self.settings_file = os.path.join(source_path, 'publish.json')
		self.hopper = tempfile.TemporaryDirectory(prefix="hedgehog_")
		self.prepared = False
		self.stdout = []
		self.stderr = []
		self.progress = None
		self.result = None

		self.start_time = pytz.utc.localize(datetime.datetime.utcnow())

		if not os.path.exists(self.settings_file):
			raise QuillPathNotFoundException()
		self.settings = {}
		if isinstance(core_settings, dict):
			self.settings = core_settings.copy()
		with open(self.settings_file) as fp:
			settings_data = json.load(fp)
		if not isinstance(settings_data, dict):
			raise InvalidQuillException()
		for k, v in settings_data.items():
			self.settings[k] = v
		if not 'system_name' in self.settings:
			self.settings['system_name'] = 'Hedgehog'
		if not 'system_version' in self.settings:
			self.settings['system_version'] = 3.0
		if not 'downloads' in self.settings:
			self.settings['downloads'] = []
		if not 'tools' in self.settings:
			self.settings['tools'] = []
		if not 'files' in self.settings:
			self.settings['files'] = []
		if not 'incoming' in self.settings:
			self.settings['incoming'] = []
		if not 'environment' in self.settings:
			self.settings['environment'] = {}
		if not 'exports' in self.settings:
			self.settings['exports'] = []
		if not 'check_hashes' in self.settings:
			self.settings['check_hashes'] = True

		if len(self.settings['incoming']) > 0:
			if not 'incoming_dir' in self.settings:
				raise IncomingDirectoryNotDefined
		if len(self.settings['tools']) > 0:
			if not 'tools_dir' in self.settings:
				raise ToolsDirectoryNotDefined

		if not 'commands' in self.settings:
			raise QuillCommandsMissing()
		shutil.copytree(self.source_path, self.hopper.name, dirs_exist_ok=True)
		if len(self.missing_files()) > 0:
			raise QuillRequiredFileMissing()
		if not 'prepare' in self.settings['commands']:
			self.settings['commands']['prepare'] = []
		if not 'import' in self.settings['commands']:
			self.settings['commands']['import'] = []

		self.prepare_command_count = len(self.settings['commands']['prepare']) + len(self.settings['incoming']) + len(self.settings['downloads']) + len(self.settings['tools'])
		if self.prepare_command_count + len(self.settings['files']) == 0:
			self.prepared = True
		self.command_count = self.prepare_command_count + len(self.settings['commands']['import'])

	def download(self, url, filename):
		user_agent = self.settings['system_name'] + "/" + str(self.settings['system_version']) + " (https://github.com/ads04r/Hedgehog)"
		save_path = os.path.join(self.hopper.name, filename)
		with requests.get(url, stream=True, headers={'User-Agent': user_agent}) as req:
			req.raise_for_status()
			with open(save_path, 'wb') as fp:
				for chunk in req.iter_content(chunk_size=2048):
					fp.write(chunk)
		return os.path.exists(save_path)

	def dump(self, path):
		for f in os.listdir(self.hopper.name):
			if f.startswith('.'):
				continue
			if f.endswith('.private'):
				continue
			src = os.path.join(self.hopper.name, f)
			if os.path.isdir(src):
				continue
			dst = os.path.join(path, f)
			shutil.copy2(src, dst)

	def prepare(self):
		if self.prepared:
			if self.progress:
				self.progress.update(self.prepare_command_count)
			return
		for dl in self.settings['downloads']:
			self.download(dl['download'], dl['localfile'])
			if self.progress:
				self.progress.update()
		for tool in self.settings['tools']:
			tool_file = os.path.join(self.settings['tools_dir'], tool)
			if os.path.exists(tool_file):
				shutil.copy2(tool_file, os.path.join(self.hopper.name, tool))
			else:
				raise ToolNotFound(tool)
			if self.progress:
				self.progress.update()
		for file in self.settings['incoming']:
			incoming_file = os.path.join(self.settings['incoming_dir'], file)
			if os.path.exists(incoming_file):
				shutil.copy2(incoming_file, os.path.join(self.hopper.name, file))
			else:
				raise IncomingFileNotFound(file)
			if self.progress:
				self.progress.update()
		env = os.environ.copy()
		for k, v in self.settings['environment'].items():
			env[k] = v
		for cmd in self.settings['commands']['prepare']:
			try:
				ret = subprocess.run(cmd, capture_output=True, shell=True, cwd=self.hopper.name, env=env, check=True)
			except subprocess.CalledProcessError as e:
				e.add_note("\nQuill error output follows...\n\n" + e.stderr.decode('utf8'))
				raise e
			stdout = ret.stdout.decode('utf8').strip()
			stderr = ret.stderr.decode('utf8').strip()
			if len(stdout) > 0:
				self.stdout.append(stdout)
			if len(stderr) > 0:
				self.stderr.append(stderr)
			if self.progress:
				self.progress.update()
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
		env = os.environ.copy()
		for k, v in self.settings['environment'].items():
			env[k] = v
		for cmd in self.settings['commands']['import']:
			try:
				ret = subprocess.run(cmd, capture_output=True, shell=True, cwd=self.hopper.name, env=env, check=True)
			except subprocess.CalledProcessError as e:
				e.add_note("\nQuill error output follows...\n\n" + e.stderr.decode('utf8'))
				raise e
			stdout = ret.stdout.decode('utf8').strip()
			stderr = ret.stderr.decode('utf8').strip()
			if len(stdout) > 0:
				self.stdout.append(stdout)
			if len(stderr) > 0:
				self.stderr.append(stderr)
			if self.progress:
				self.progress.update()
		import_file = os.path.join(self.hopper.name, self.settings['properties']['import_file'])
		if not os.path.exists(import_file):
			print(import_file)
			raise QuillHasNoImportFile

		if self.progress:
			self.progress.refresh()
		g = Graph()
		g.bind('prov', URIRef("http://www.w3.org/ns/prov#"))
		g.parse(import_file)

		self.end_time = pytz.utc.localize(datetime.datetime.utcnow())

		quill_ref = URIRef(self.uri)
		g.add((quill_ref, DC.date, Literal(self.end_time.strftime("%Y-%m-%d %H:%M:%S %z"), datatype=XSD.dateTime)))

		# prov and other metadata goes here

		self.result = g

		for export in self.settings['exports']:
			if len(export) != 2:
				continue
			with open(os.path.join(self.hopper.name, export[0]), 'w') as fp:
				fp.write( g.serialize(format=export[1]) )

		return g

	def hashes(self):
		if not self.prepared:
			raise QuillNotPrepared()
		ret = {}
		for f in os.listdir(self.hopper.name):
			if f.startswith('.'):
				continue
			f_path = os.path.join(self.hopper.name, f)
			if os.path.isdir(f_path):
				continue
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
