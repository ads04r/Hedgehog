from rdflib import Graph, URIRef, BNode, Literal
from urllib.parse import urlencode
import requests

# Based quite heavily on the work of Christopher Gutteridge
# https://github.com/cgutteridge/Graphite/blob/master/Graphite/Description.php

class Description():

	def __init__(self, resource):
		self.resource = resource
		self.graph = resource.graph
		self.routes = {}
		self.tree = {'+': {}, '-': {}}

	def add_route(self, route):
		self.routes[route] = True
		preds = route.split('/')
		treeptr = self.tree
		for pred in preds:
			dir = '+'
			if pred.startswith('-'):
				pred = pred[1:]
				dir = '-'
			if not pred in treeptr[dir]:
				treeptr[dir][pred] = {'+': {}, '-': {}}
			treeptr = treeptr[dir][pred]

	def __tograph(self, tree, resource, new_graph):
		for p in self.graph.predicates(unique=True):
			code = self.graph.qname(p)
			if ((not '*' in tree['+']) & (not code in tree['+'])):
				continue
			for o in resource.objects(predicate=p):
				if isinstance(o, Literal):
					new_graph.add((resource.identifier, p, o))
					continue
				new_graph.add((resource.identifier, p, o.identifier))
				if '*' in tree['+']:
					self.__tograph(tree['+']['*'], o, new_graph)
				elif code in tree['+']:
					self.__tograph(tree['+'][code], o, new_graph)
		for p in self.graph.predicates(unique=True):
			code = self.graph.qname(p)
			if ((not '*' in tree['-']) & (not code in tree['-'])):
				continue
			for s in resource.subjects(predicate=p):
				new_graph.add((s.identifier, p, resource.identifier))
				if '*' in tree['-']:
					self.__tograph(tree['-']['*'], s, new_graph)
				elif code in tree['-']:
					self.__tograph(tree['-'][code], s, new_graph)

	def __expand_uri(self, qname):
		parse = qname.split(':', 2)
		if len(parse) < 2:
			return qname
		try:
			return str(dict(self.graph.namespaces())[parse[0]]) + parse[1]
		except:
			return qname

	def __tosparql(self, tree, suffix, in_dangler=None, sparqlprefix=""):
		bits = []
		if in_dangler is None:
			in_dangler = "<" + str(self.resource.identifier) + ">"
		i = 0
		for dir_k, routes in tree.items():
			dir = str(dir_k)
			if len(routes) == 0:
				continue
			pres = []
			if '*' in routes:
				sub = f"?s{suffix}_{i}"
				pre = f"?p{suffix}_{i}"
				obj = f"?o{suffix}_{i}"
				if dir == '+':
					out_dangler = obj
					sub = in_dangler
				else:
					out_dangler = sub
					obj = in_dangler
				construct = f"{sub} {pre} {obj} . "
				where = f"{sparqlprefix} {sub} {pre} {obj} . "
				if '*' in routes:
					bits_from_routes = self.__tosparql(routes['*'], f"{suffix}_{i}", out_dangler, "")
					i = i + 1
					for bit in bits_from_routes:
						construct = construct + bit['construct']
						where = where + " OPTIONAL { " + bit['where'] + " } "
				bits.append({'where': where, 'construct': construct})
				for pred_k, route in routes.items():
					pred = str(pred_k)
					if pred == '*':
						continue
					pre = "<" + self.__expand_uri(pred) + ">"
					bits_from_routes = self.__tosparql(route, f"{suffix}_{i}", out_dangler, "{sparqlprefix} {sub} {pre} {obj} . ")
					i = i + 1
					for bit in bits_from_routes:
						bits.append(bit)
			else:
				for pred_k in routes.keys():
					pred = str(pred_k)
					sub = f"?s{suffix}_{i}"
					pre = "<" + self.__expand_uri(pred) + ">"
					obj = f"?o{suffix}_{i}"
					if dir == '+':
						out_dangler = obj
						sub = in_dangler
					else:
						out_dangler = sub
						obj = in_dangler
					bits_from_routes = self.__tosparql(routes[pred], f"{suffix}_{i}", out_dangler, "")
					i = i + 1
					construct = f"{sub} {pre} {obj} . "
					where = f"{sparqlprefix} {sub} {pre} {obj} . "
					for bit in bits_from_routes:
						construct = construct + bit['construct']
						where = where + " OPTIONAL { " + bit['where'] + " } "
					bits.append({'where': where, 'construct': construct})
		return bits

	def to_graph(self):
		new_graph = Graph()
		self.__tograph(self.tree, self.resource, new_graph)
		return new_graph

	def get_sparql(self):
		queries = []
		for bit in self.__tosparql(self.tree, "", None, ""):
			queries.append("CONSTRUCT { " + bit['construct'] + " } WHERE { " + bit['where'] + " }")
		return queries

	def load_remote(self, endpoint):
		g = Graph()
		for query in self.get_sparql():
			url = endpoint + '?' + urlencode({'query': query})
			with requests.get(url) as r:
				g.parse(data=r.content)
		return g
