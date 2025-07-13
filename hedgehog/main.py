#!/usr/bin/python3

from hedgehog import Hedgehog
import argparse

def main():

	parser = argparse.ArgumentParser(prog ='hedgehog', description ='Hedgehog RDF Publisher')
	parser.add_argument('operation')
	parser.add_argument('quills', nargs='*')

	args = parser.parse_args()
	hh = Hedgehog()

	print(hh)

if __name__ == '__main__':
	main()
