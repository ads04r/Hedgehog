#!/usr/bin/python3

import argparse

def main():

	parser = argparse.ArgumentParser(prog ='hedgehog', description ='Hedgehog RDF Publisher')
	parser.add_argument('operation')
	parser.add_argument('quills', nargs='*')

	args = parser.parse_args()

	print(args.operation)
	print(args.quills)

if __name__ == '__main__':
	main()
