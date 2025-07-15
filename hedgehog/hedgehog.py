import pathlib, os, json, datetime
from quill import Quill
from exporters import VirtuosoTriplestore

class Hedgehog():

	def __init__(self):

		self.settings_path = os.path.join(pathlib.Path.home(), '.config', 'hedgehog')
		self.settings_file = os.path.join(self.settings_path, 'config.json')

		self.settings = {}
		if os.path.exists(self.settings_file):
			with open(self.settings_file, 'r') as fp:
				self.settings = json.load(fp)

		if not 'quills_dir' in self.settings:
			self.settings['quills_dir'] = os.path.join(self.settings_path, 'quills')
		if not 'tools_dir' in self.settings:
			self.settings['tools_dir'] = os.path.join(self.settings_path, 'tools')
		if not 'incoming_dir' in self.settings:
			self.settings['incoming_dir'] = os.path.join(self.settings_path, 'incoming')
		if not 'publish' in self.settings:
			self.settings['publish'] = []

		os.makedirs(self.settings_path, exist_ok=True)
		os.makedirs(self.settings['quills_dir'], exist_ok=True)
		os.makedirs(self.settings['tools_dir'], exist_ok=True)
		os.makedirs(self.settings['incoming_dir'], exist_ok=True)

	def save_config(self):

		with open(self.settings_file, 'w') as fp:
			fp.write(json.dumps(self.settings))

	def list_quills(self):

		ret = []
		for f in os.listdir(self.settings['quills_dir']):
			if f.startswith('.'):
				continue
			path = os.path.join(self.settings['quills_dir'], f)
			if os.path.isdir(path):
				if os.path.exists(os.path.join(path, 'publish.json')):
					ret.append(f)
			else:
				if path.lower().endswith('.zip'):
					ret.append(f)
		return ret

	def get_quill(self, id):

		quill_path = os.path.join(self.settings['quills_dir'], id)
		quill_zip_path = os.path.join(self.settings['quills_dir'], id + '.zip')

		if os.path.exists(quill_path):
			return Quill(source_path=quill_path, core_settings=self.settings)

		if os.path.exists(quill_zip_path):
			return Quill(source_path=quill_zip_path, core_settings=self.settings)

	def publish(self, id):

		quill = self.get_quill(id)

		if not 'exports' in quill.settings:
			quill.settings['exports'] = []
		if len(quill.settings['exports']) == 0:
			quill.settings['exports'] = [[id + '.rdf', 'pretty-xml'], [id + '.ttl', 'turtle'], [id + '.json', 'json-ld']]

		quill.prepare()
		g = quill.run()
		ds = datetime.datetime.now().strftime("%Y-%m-%d")

		for item in self.settings['publish']:
			if not 'action' in item:
				continue
			if item['action'] == 'dump':

				if not 'path' in item:
					continue
				dump_path = os.path.join(item['path'], id, ds)
				os.makedirs(dump_path, exist_ok=True)
				quill.dump(dump_path)

			if item['action'] == 'virtuoso':

				if not 'url' in item:
					continue
				store = VirtuosoTriplestore(item['url'])
				store.username = item['auth'][0]
				store.password = item['auth'][1]
				store.import_graph(item['graph_prefix'] + id, g.serialize(format='ntriples'))

# {'system_name': 'Hedgehog', 'tools_dir': '/home/ash/tools/hedgehog/tools', 'rdf_base': 'http://id.flarpyland.com/',
# 'publish': [{'url': 'http://data.southampton.ac.uk/dumps', 'path': '/home/hedgehog/dumps', 'action': 'dump'},
#             {'url': 'http://127.0.0.1:8080/openrdf-sesame/repositories/data-soton-dev/statements', 'action': 'sesame'}],
# 'quills_dir': '/home/ash/tools/hedgehog/quills', 'incoming_dir': '/home/ash/tools/hedgehog/incoming'
