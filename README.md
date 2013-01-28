Hedgehog RDF Publisher
======================

By Ashley Smith and Christopher Gutteridge
ads04r@ecs.soton.ac.uk, cjg@ecs.soton.ac.uk
University of Southampton
http://data.southampton.ac.uk/

Hedgehog is a system for publishing datasets in RDF. The core system runs
scripts (known as 'quills') which pull data from a source and expose the
data as RDF triples. It then performs a set of tasks on those triples.
Examples include

* Converting the data to RDF documents in various formats, such as Turtle
* Importing the triples into a triplestore such as 4store
* Uploading the data documents to a server for publishing on the web

