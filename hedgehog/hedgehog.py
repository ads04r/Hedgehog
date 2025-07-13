import pathlib, os

class Hedgehog():

	def __init__(self):

		settings_path = os.path.join(pathlib.Path.home(), '.config', 'hedgehog')
		quills_path = os.path.join(settings_path, 'quills')
		settings_file = os.path.join(settings_path, 'config.json')
		os.makedirs(settings_path, exist_ok=True)
		os.makedirs(quills_path, exist_ok=True)

