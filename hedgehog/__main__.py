#!/usr/bin/python3

from .hedgehog import Hedgehog
from .quill import Quill
from .site import Site
import argparse, os, pathlib, json, yaml

def main():

	parser = argparse.ArgumentParser(prog ='hedgehog', description ='Hedgehog RDF Publisher')
	operations = parser.add_subparsers(dest='operation', required=True, help='the operation Hedgehog is to perform')

	parser_list = operations.add_parser('list', help='list all of the available quills')

	parser_publish = operations.add_parser('publish', help='publish the specified quill(s) to the database')
	parser_publish.add_argument('quills', nargs='+', help='the id of one or more quills')
	parser_publish.add_argument('-f', '--force', action='store_true', help='force a republish of the specified quills, even if hash checking is turned on and nothing has changed')
	parser_publish.add_argument('-q', '--quiet', action='store_true', help='don\'t output anything to the console during publishing')

	parser_init = operations.add_parser('init', help='initialise a new web project in the specified directory')
	parser_init.add_argument('path', type=pathlib.Path, nargs='?', help='the path in which to initialise, uses the current directory if omitted')

	parser_build = operations.add_parser('build', help='build a web project into a static site')
	parser_build.add_argument('path', type=argparse.FileType('r'), nargs='?', help='the build.json file from which to build, looks in the current directory if omitted')

	args = parser.parse_args()
	hh = Hedgehog()

	if args.operation == 'list':

		for quill in sorted(hh.list_quills()):
			print(quill)

	if args.operation == 'publish':

		for quill in args.quills:
			hh.publish(quill, force=args.force, quiet=args.quiet)

	if args.operation == 'init':

		if args.path is None:
			path = os.getcwd()
		else:
			path = os.path.abspath(args.path)

	if args.operation == 'build':

		config = {}
		config_file = None
		if not args.path is None:
			config_file = args.path
		else:
			config_path = os.path.join(os.getcwd(), "build.json")
			if not os.path.exists(config_path):
				config_path = os.path.join(os.getcwd(), "build.yaml")
			if os.path.exists(config_path):
				config_file = open(config_path, 'r')

		if config_file:
			if config_file.name.endswith(".json"):
				config = json.load(config_file)
			if config_file.name.endswith(".yaml"):
				config = yaml.load(config_file, Loader=yaml.Loader)

		site = Site(config)
		site.build()

if __name__ == '__main__':
	main()
