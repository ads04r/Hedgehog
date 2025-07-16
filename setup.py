from setuptools import setup
import os
base_path = os.path.dirname(os.path.abspath(__file__))
req_file = os.path.join(base_path, 'requirements.txt')
with open(req_file, 'r') as fp:
	req = fp.read().splitlines()
setup(name='hedgehog', version='3.0.0', packages=['hedgehog'], entry_points={'console_scripts': ['hedgehog = hedgehog.__main__:main']}, install_requires=req)
