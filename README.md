Hedgehog RDF Publisher
======================

By Ash Smith

Based heavily on the work of Christopher Gutteridge, University of Southampton

Hedgehog is a system for publishing datasets in RDF. The core system runs
scripts (known as 'quills') which pull data from a source and expose the
data as RDF triples. It then performs a set of tasks on those triples.
Examples include

* Converting the data to RDF documents in various formats, such as Turtle
* Automatically adding meta-data about the data, including provenance
* Publishing the triples into a triplestore such as 4store
* Uploading the data documents to a server for publishing on the web
* Exporting smaller chunks of the data based on a template

License
-------
This work is released under the GNU General Public License version 2.0
http://www.gnu.org/licenses/gpl-2.0.html


General Documentation
=====================

Installation
------------

Hedgehog now comes with a nice web UI, as well as keeping its command-line
heritage. To ensure this works, clone the repository into a directory
(eg /usr/local/hedgehog) and ensure that Apache's htdocs root is the
var/www directory beneath this. Also, add the bin directory to the PATH
variable to be able to run the scripts from the command line from anywhere.

How to run Hedgehog
-------------------

Hedgehog normally runs via crontab. Active quills can be run on a regular
basis based on their needs, typically once a day but occasionally more
or less often. 

To run hedgehog with a specific quill: 

    publish_dataset <quill_name_here> [<options>]

Options are:

* ```--log```       Logs to a log file, rather than STDOUT
* ```--force```     Ignores hash check, always publishes
* ```--republish``` Doesn't run quill, looks for a previous successful
                    publish and simply re-publishing this

Quills
------

A Quill has a publish.json file which is the entry point and only mandatory
element. It can also contain other files and scripts which are necessary to
acquire and/or process the data into the correct format.

* The first thing Hedgehog does is creates a new temporary directory and
  copies all of the files from the Quill directory into that directory.
  The quill can be a ZIP archive.
* It then goes to the Download section of the quill publish.json and
  performs all download steps specified.
* It then goes to the tools section of the quill publish.json and copies
  any required tools from the Hedgehog tools directory into the temporary
  directory.
* It then copies any files listed in the "incoming" section from the
  incoming directory (specified within the config of Hedgehog itself) into
  the temporary directory.
* It then runs any commands in the commands > prepare section on the command
  line.
* (Optional) It then compares the dataset files with the hashes of the
  previously processed dataset, and if it determines that the datasets are
  identical to the last known versions then it stops here. Whether this happens
  or not is usually specified in the publish.json file, but may be overridden
  with the command line switch --force.
* Otherwise it moves on to the import section of the commands section.
* After the import section is complete, Hedgehog does some processing which
  is specified globally by Hedgehog and is independent of the Quill.
* Finally the commands in the completed section of the commands section are
  executed.

