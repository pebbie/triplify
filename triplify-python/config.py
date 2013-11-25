"""
This file contains the configuration for triplify.
Triplify rapidly simplifies the creation of structured content for Web 2.0
mashups and Semantic Web applications.
Triplyfy uses a number of SQL queries, whose results are converted into
either RDF/N3, JSON or Linked Data.

@version $Id:$
@license LGPL
@copyright 2008 Remon Georgy (remon.sherin@gmail.com)
"""
import MySQLdb

triplify = {}

"""
Triplify uses a PDO object to connect to the database.
The following line creates an appropriate PDO object for a MySQL database.
Please adjust the values for database name, user and password.
For maximum security, you can create a database user specificially for
Triplify, which has solely readable access to the columns of your database
schema, which should be made public. Alternatively, you can include the
configuration of your Web application and reuse its credentials here.
"""
triplify['db'] = MySQLdb.connect(host = "localhost",user = "user",passwd = "password",db = "database")

"""
Triplify uses URIs to identify objects. In order to simplify their handling
you should define shortcuts (i.e. namespace prefixes) for all namespaces
from which you want to use URIs.
A 'vocabulary' entry entry is mandatory - it specifies, which default prefix
should be used for vocabulary elements such as classes and properties. Other
than the prefix for instances this prefix should be shared between different
installations of a certain Web application on the Web.
"""

triplify['namespaces']= {
    'vocabulary':'http://byteflow.rgeorgy.com/triplify/vocabulary/',
    'rdf':'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
    'rdfs':'http://www.w3.org/2000/01/rdf-schema#',
    'owl':'http://www.w3.org/2002/07/owl#',
    'foaf':'http://xmlns.com/foaf/0.1/',
    'sioc':'http://rdfs.org/sioc/ns#',
    'sioctypes':'http://rdfs.org/sioc/types#',
    'dc':'http://purl.org/dc/elements/1.1/',
    'dcterms':'http://purl.org/dc/terms/',
    'skos':'http://www.w3.org/2004/02/skos/core#',
    'tag':'http://www.holygoat.co.uk/owl/redwood/0.1/tags/',
    'xsd':'http://www.w3.org/2001/XMLSchema#',
    'update':'http://triplify.org/vocabulary/update#'}

"""
The core of triplify are SQL queries, which select the information to be made
available.
You can provide a number of arbitrary queries. Each query, however, should
select information about an object of a certain type. This type, which serves
as an index in the associative queries configuration array, is also used to
construct corresponding URIs for the objects returned by the query.
The first column returned by the query represents the ID of the object and
has to be named "id", all other columns represent characteristics (or
properties of this object). As column identifier you should reuse existing
vocabularies whenever possible. If your "user" table, for example, contains a
column named "first_name" this can be easily mapped to the corresponding FOAF
property using: "SELECT id,first_name AS 'foaf:firstName' FROM user".
You can use the following column naming convention in order to inform
Triplify about the datatype or language of a column:
SELECT id,price AS 'price^^xsd:decimal',desc AS 'rdf:label@en' FROM products
However, Triplify tries to autodetect and convert timestamps appropriately.
Similarly, you can indicate that a column represents an objectProperty
pointing to other objects (foreign key):
SELECT id,user_id 'sioc:has_creator->user'
Oonly select information, which does not contain sensitive information and
can be made public. For example, email adresses and password (hashes) should
never be exposed. However, you can use the database function SHA to
mask email addresses, e.g.:
SELECT SHA(CONCAT('mailto:',email)) AS 'foaf:mbox_sha1sum' FROM users
The following queries are example queries and have to be replaced by queries
suitable for your database schema.
"""

triplify['queries'] = {
    'post':[
        """SELECT 
            p.id AS id,
            p.name AS 'dc:title',
            p.text AS 'sioc:content',
            u.username AS 'sioc:has_creator',
            p.upd_date AS 'dcterms:modified',
            p.date AS 'dcterms:created'
            FROM blog_post p INNER JOIN auth_user u ON(p.author_id=u.id)"""],
    'user':[
        """SELECT 
            id,
            username AS 'foaf:name',
            SHA(CONCAT('mailto:',email)) AS 'foaf:mbox_sha1sum'
            FROM auth_user"""],
    'update':[
        """SELECT 
            p.upd_date AS id,
            p.id AS 'update:updatedResource->post' 
            FROM blog_post p"""]}
        
"""
Some of the columns of the Triplify queries will contain references to other
objects rather than literal values. The following configuration array
specifies, which columns are references to objects of which type.
"""

triplify['objectProperties']={
    'sioc:has_creator':'user',}

"""
Objects are classified according to their type. However, you can specify
a mapping here, if objects of a certain type should be associated with a
different class (e.g. classify all users as 'foaf:person'). If you are
unsure it is safe to leave this configuration array empty.
"""

triplify['classMap']={
    'user':'foaf:person'}

"""
You can attach license information to your content.
A popular license is Creative Commons Attribution, which allows sharing and
remixing under the condition of attributing the original author.
"""
triplify['license']='http://creativecommons.org/licenses/by/3.0/us/'

"""
Additional metadata
You can add arbitrary metadata. The keys of the following array are
properties, the values will be represented as respective property values.
"""

triplify['metadata']={
    'dc:title':'',
    'dc:publisher':''}

"""
Set this to true in order to register your linked data endpoint with the
Triplify registry (http://triplify.org/Registry).
Registering is absolutely recommended, since that allows other Web sites
(e.g. peer Web applications, search engines and mashups) to easily find your
content. Requires PHP ini variable allow_url_fopen set to true.
You can also register your data source manually by accessing register.php in
the triplify folder, or at: http://triplify.org/Registry
"""

triplify['register']=True

"""
You can specify for how long generated files should be cached. For smaller
Web applications it is save to disable caching by setting this value to zero.
"""

#triplify['TTL']=100
"""
Directory to be used for caching
"""
triplify['cachedir']='cache/'

"""
Linked Data Depth
Specify on which URI level to expose the data - possible values are:
 - Use 0 or ommit to expose all available content on the highest level
   all content will be exposed when /triplify/ is accessed on your server
 - Use 1 to publish only links to the classes on the highest level and all
   content will be exposed when for example /triplify/user/ is accessed.
 - Use 2 to publish only links on highest and classes level and all
   content will be exposed on the instance level, e.g. when /triplify/user/1/
   is accessed.
"""

triplify['LinkedDataDepth']='2'

"""
Callback Functions
Some of the columns of the Triplify queries will contain data, which has to
be processed before exposed as RDF (literals). This configuration array maps
column names to respective functions, which have to take the data value as a
parameter and return it processed.
"""

triplify['CallbackFunctions']={}