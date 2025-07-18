import requests, pymysql
from requests.auth import HTTPDigestAuth
from rdflib import Graph

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

class MySQLTriplestoreEmulator(Triplestore):
	def __init__(self, url):
		self.url = url
		self.username = ''
		self.password = ''
		self.database = ''
		self.db = None
	def connect(self):
		self.db = pymysql.connect(host=self.url, user=self.username, password=self.password, database=self.database)
	def get_prefix_id(self, uri):
		if self.db is None:
			self.connect()
		query = "SELECT id FROM prefix WHERE uri='" + self.db.escape_string(uri) + "';"
		ret = 0
		cursor = self.db.cursor()
		cursor.execute(query)
		for row in cursor.fetchall():
			ret = int(row[0])
		if ret > 0:
			return ret
		query = "INSERT INTO prefix (prefix, uri, label) VALUES ('', '" + self.db.escape_string(uri) + "', '');"
		cursor.execute(query)
		ret = int(cursor.lastrowid)
		return ret
	def get_uri_id(self, uri):
		if self.db is None:
			self.connect()
		name = uri.replace('#', '/').strip('/').split('/')[-1]
		prefix = uri[0:(len(uri) - len(name))]
		if prefix.endswith('://'):
			prefix = prefix + name
			name = ""
		prefix_id = self.get_prefix_id(prefix)
		query = "SELECT id FROM uris WHERE prefix='" + str(prefix_id) + "' AND name='" + self.db.escape_string(name) + "';"
		ret = 0
		cursor = self.db.cursor()
		cursor.execute(query)
		for row in cursor.fetchall():
			ret = int(row[0])
		if ret > 0:
			return ret
		query = "INSERT INTO uris (prefix, name) VALUES ('" + str(prefix_id) + "', '" + self.db.escape_string(name) + "');"
		cursor.execute(query)
		ret = int(cursor.lastrowid)
		return ret
	def import_graph(self, graph, data):
		id = graph.replace('#', '/').strip('/').split('/')[-1]
		if isinstance(data, Graph):
			g = data
		else:
			g = Graph()
			g.parse(data=data)
		if self.db is None:
			self.connect()
		cursor = self.db.cursor()
		cursor.execute("DELETE FROM triples WHERE quill='" + self.db.escape_string(id) + "';")
		insert_simple_values = []
		insert_complex_values = []
		for res in g.subjects():
			subject_uri = str(res)
			subject_id = self.get_uri_id(subject_uri)
			for rel in g.predicates(subject=res):
				predicate_uri = str(rel)
				predicate_id = self.get_uri_id(predicate_uri)
				for object in g.objects(subject=res, predicate=rel):
					object_id = 0
					if object.__class__.__name__ == 'URIRef':
						object_uri = str(object)
						object_id = self.get_uri_id(object_uri)
					elif object.__class__.__name__ == 'BNode':
						object_uri = str(object.n3())
						object_id = self.get_uri_id(object_uri)
					if object_id == 0:
						object_type_uri = str(object.datatype)
						object_type_id = self.get_uri_id(object_type_uri)
						insert_complex_values.append("('" + str(subject_id) + "', '" + str(predicate_id) + "', '" + self.db.escape_string(str(object)) + "', '" + str(object_type_id) + "', '" + self.db.escape_string(id) + "')")
					else:
						insert_simple_values.append("('" + str(subject_id) + "', '" + str(predicate_id) + "', '" + str(object_id) + "', '" + self.db.escape_string(id) + "')")
			if len(insert_simple_values) > 5000:
				query = "INSERT INTO triples (s, p, o, quill) VALUES " + (','.join(insert_simple_values)) + ";"
				cursor.execute(query)
				insert_simple_values = []
			if len(insert_complex_values) > 5000:
				query = "INSERT INTO triples (s, p, o_text, o_type, quill) VALUES " + (','.join(insert_complex_values)) + ";"
				cursor.execute(query)
				insert_complex_values = []
		if len(insert_simple_values) > 0:
			query = "INSERT INTO triples (s, p, o, quill) VALUES " + (','.join(insert_simple_values)) + ";"
			cursor.execute(query)
		if len(insert_complex_values) > 0:
			query = "INSERT INTO triples (s, p, o_text, o_type, quill) VALUES " + (','.join(insert_complex_values)) + ";"
			cursor.execute(query)

