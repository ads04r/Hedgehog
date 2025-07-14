#!/usr/bin/python3

from hedgehog import Hedgehog
from quill import Quill
import argparse

def main():

	parser = argparse.ArgumentParser(prog ='hedgehog', description ='Hedgehog RDF Publisher')
	parser.add_argument('operation')
	parser.add_argument('quills', nargs='*')

	args = parser.parse_args()
	hh = Hedgehog()

	if args.operation == 'list':

		for quill in sorted(hh.list_quills()):
			print(quill)

	if args.operation == 'publish':

		for quill in args.quills:
			print(quill)
			q = hh.get_quill(quill)
			q.prepare()
			q.run()

if __name__ == '__main__':
	main()
