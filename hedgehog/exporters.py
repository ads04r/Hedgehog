import requests
from requests.auth import HTTPDigestAuth

class Triplestore():
	def __init__(self, url):
		self.url = url
		self.username = ''
		self.password = ''
	def import_graph(self, graph, data):
		pass

class VirtuosoTriplestore(Triplestore):
	def import_graph(self, graph, data):
		url = self.url.rstrip('/') + '/sparql-graph-crud-auth?graph-uri=' + graph
		with requests.post(url, data=data, auth=HTTPDigestAuth(self.username, self.password)) as r:
			return r.status_code

