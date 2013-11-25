from config import triplify
import urllib

def index(req):
    req.content_type = "text/html"
    server = req.server
    serverURI = 'http://' + req.hostname + ("" if (server.port == 80) else ":"+server.port )
    baseURI = serverURI + req.uri.replace('register.py','')
    url = 'http://triplify.org/register/?'+urllib.urlencode({'url':baseURI, 'type': triplify['namespaces'].get('vocabulary', '')})
    try:
        registeringPage = urllib.urlopen(url)
        registeringPage.close()
    except IOError:
        req.write('Please <a href="'+url+'">register manually</a>!')
    req.write(url)