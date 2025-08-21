import json

class Site:

	def __init__(self, config):

		self.config = config

	def build(self):

		print(json.dumps(self.config, indent=4))

