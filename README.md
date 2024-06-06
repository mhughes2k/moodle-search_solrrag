# Moodle global search engine for SOLR that supports Vector Searching
This is an extension to the Moodle SOLR Search Engine that enables vector searches via the DenseVectorField.

This plugin depends on (local_ai)[https://github.com/mhughes2k/moodle-local_ai] plugin to interface with an Open AI 
API compliant backend (OpenAI, Ollama, LM Studio etc)

## Solr field configuration
It is necessary to add some fields to the solr configuration to define the vector fields.
These fields need to match the length/dimension size of the vector result that your AI provider will return
See https://platform.openai.com/docs/api-reference/embeddings/create for information on the dimensions for Open AI models.

Different Open AI Embeddings can be found at https://platform.openai.com/docs/models/embeddings along with their output dimensions,
but this may be different if you use a different AI backend.

You can use something like Postman with the following POST request to set this up, to the 
`http://localhost:8983/solr/<corename>/schema` end point:

```
{
    "add-field-type" : {
    "name":"knn_vector_1356",
    "class":"solr.DenseVectorField",
    "vectorDimension":1356,
    "similarityFunction":"cosine",
    "knnAlgorithm":"hnsw"
  },
  {
    "add-field-type" : {
    "name":"knn_vector_3072",
    "class":"solr.DenseVectorField",
    "vectorDimension":1356,
    "similarityFunction":"cosine",
    "knnAlgorithm":"hnsw"
  },
  {
    "add-field-type" : {
    "name":"knn_vector_768",
    "class":"solr.DenseVectorField",
    "vectorDimension":768,
    "similarityFunction":"cosine",
    "knnAlgorithm":"hnsw"
  }
}
```
or you can configure it via the XML config file:
```
<fieldType name="knn_vector_1536" class="solr.DenseVectorField" vectorDimension="1536" similarityFunction="cosine">
<fieldType name="knn_vector_3072" class="solr.DenseVectorField" vectorDimension="3072" similarityFunction="cosine">
```
At the moment the systemw will attempt to store the embedding in a field called "knn_vector_<dimensionsize>" based on the size of the result it gets back.
