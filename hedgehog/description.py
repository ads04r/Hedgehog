from rdflib import Graph

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
