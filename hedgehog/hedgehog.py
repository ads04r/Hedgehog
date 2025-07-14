import pathlib, os

class Hedgehog():

	def __init__(self):

		self.settings_path = os.path.join(pathlib.Path.home(), '.config', 'hedgehog')
		self.quills_path = os.path.join(self.settings_path, 'quills')
		self.settings_file = os.path.join(self.settings_path, 'config.json')
		os.makedirs(self.settings_path, exist_ok=True)
		os.makedirs(self.quills_path, exist_ok=True)

