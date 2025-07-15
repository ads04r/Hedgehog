#!/usr/bin/python3

from hedgehog import Hedgehog
from quill import Quill
import argparse

def main():

	parser = argparse.ArgumentParser(prog ='hedgehog', description ='Hedgehog RDF Publisher')
	parser.add_argument('operation', choices=['publish', 'list'], help='the operation Hedgehog is to perform')
	parser.add_argument('quills', nargs='*', help='the id of one or more quills')
	parser.add_argument('-f', '--force', action='store_true', help='force a republish of the specified quills, even if hash checking is turned on and nothing has changed.')

	args = parser.parse_args()
	hh = Hedgehog()

	if args.operation == 'list':

		for quill in sorted(hh.list_quills()):
			print(quill)

	if args.operation == 'publish':

		for quill in args.quills:
			hh.publish(quill, force=args.force)

if __name__ == '__main__':
	main()
