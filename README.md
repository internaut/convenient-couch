# ConvenientCouch - A PHP Class to conveniently query CouchDB

ConvenientCouch is a single PHP class on top of the ["Sag" CouchDB Library](https://github.com/sbisbee/sag) that comes with many methods for easily querying views in CouchDB. Querying views with single keys, multiple keys or key ranges and multidimensional keys is made very easy with ConvenientCouch. Fetching linked documents as described in the CouchDB manual (*[Joins With Views](http://docs.couchdb.org/en/latest/couchapp/views/joins.html)*) is also simplified because this ConvenientCouch also supports combining linked documents to a single result object.

The classe's methods are fully documented in the source code.

## Usage

```
// create a ConvenientCouch object for DB "example_db "that will use the views in "design_doc_with_views"
// (factory functions are also available)
$cc = new ConvenientCouch('example_db', 'design_doc_with_views');

// query my_view with key="my_value"
$result = $cc->fetchViewWithKeyMatch('my_view', 'my_value');
// ...

// query my_view with keys="my_value1,my_value2"
$result = $cc->fetchViewWithKeysMatch('my_view', array('my_value1', 'my_value2');
// ...

// query my_view with startkey="my_value1"&endkey="my_value2"
$result = $cc->fetchViewWithKeyRange('my_view', array('my_value1', 'my_value2');
// ...

// nested (multidimensional) keys are also possible: query my_view with
// startkey=["val1","subval1"]&endkey=["val2","subval2"]
$result = $cc->fetchViewWithKeyRange('my_view', array(array('val1', 'subval1'), array('val2', 'subval2'));
// ...

// query my_view with "grouping=2"
$result = $cc->fetchViewWithGrouping('my_view', 2);

// more combinations of key matching and grouping can be achieved by using fetchView()
// here we also set includeDocs=true to later create single result objects from linked documents
$linkedDocs = $cc->fetchView('linked_docs_view', 'doc1', ConvenientCouch::$KEY_LOOKUP_SINGLE, null, true);
$resultObject = $cc->composeSingleDocFromLinkedDocs($linkedDocs);
```

## Dependencies

* ["Sag" CouchDB Library](https://github.com/sbisbee/sag) -- included as submodule in *lib/sag*
