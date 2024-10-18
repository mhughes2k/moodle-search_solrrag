<?php

namespace search_solrrag;

use local_ai\api;
use local_ai\LoggerAwareTrait;
use local_ai\LoggerAwareInterface;
use search_solrrag\document;
use search_solrrag\schema;

use \local_ai\AIProvider;
use \local_ai\aiclient;
use \local_ai\AiException;

class engine extends \search_solr\engine implements LoggerAwareInterface {
    use LoggerAwareTrait;

    /**
     * @var AIProvider AI rovider object to use to generate embeddings.
     */
    protected ?AIClient $aiclient = null;
    protected ?AIProvider $aiprovider = null;

    public function __construct(bool $alternateconfiguration = false)
    {
        parent::__construct($alternateconfiguration);
        // AI Retrieval support.
        // Set up AI provider if it's available.
        // Ideally we'd be using a Moodle AI provider to tell us which LLM to use for generating embeddings, and
        // then simply calling the API and get some results back...but we don't have that yet.
        // So we'll fudge this for the moment and leverage an OpenAI Web Service API via a simple HTTP request.
        $aiproviderid = get_config('search_solrrag', 'aiprovider');
        if (empty($aiproviderid)) {
            return;
        }
        if (false === ($aiprovider = api::get_provider($aiproviderid))) {
            if (isset($CFG->upgraderunning)) {
                mtrace("Provider not available during upgrade");
            } else {
                \core\notification::add("Provider not available", \core\notification::WARNING);
                //throw new \moodle_exception("providernotavailable", 'local_ai', $aiproviderid);
            }
        } else {
            $this->aiprovider = $aiprovider;
            $this->aiclient = !is_null($aiprovider) ? new AIClient($aiprovider) : null;
            $this->setLogger($aiprovider->get_logger());
        }

    }

    public function is_server_ready()
    {

        $configured = $this->is_server_configured();
        if ($configured !== true) {
            return $configured;
        }

        // As part of the above we have already checked that we can contact the server. For pages
        // where performance is important, we skip doing a full schema check as well.
        if ($this->should_skip_schema_check()) {
            return true;
        }

        // Update schema if required/possible.
        $schemalatest = $this->check_latest_schema();
        if ($schemalatest !== true) {
            return $schemalatest;
        }

        // Check that the schema is already set up.
        try {
            $schema = new schema($this);
            $schema->validate_setup();
        } catch (\moodle_exception $e) {
            return $e->getMessage();
        }

        return true;
    }

    /**
     * Adds a document to the engine, optionally (if available) generating embeddings for it.
     * @param $document
     * @param $fileindexing
     * @return bool
     * @throws \coding_exception
     */
    public function add_document($document, $fileindexing = false) {
        $docdata = $document->export_for_engine();
        $this->logger->info("Adding document to search engine");
        if ($this->aiprovider->use_for_embeddings() && $this->aiclient) {
            $this->logger->info("Generating vector using document content");
            // 1. Chunk $content in to parts based on some strategy
            $this->logger->info("Splitting \"{$docdata['content']}");
            $chunks = $this->chunk($docdata['content']);
            $chunkcount = count($chunks);
            $this->logger->info("{$chunkcount} chunks generated for {$docdata['title']}");
            if ($chunkcount > 1) {
                $filedocs = [];
                // 2. Fetch an embedding for each part.
                // 3. Add the main $filedoc, as well as each of the chunks, ensuring they
                // have  a reference to the main $filedoc.
                $i = 1;
                foreach ($chunks as $chunk) {
                    $chunkdoc = $docdata;
                    $chunkdoc['content'] = $chunk;
                    $this->logger->info("Getting vector for chunk {$i}/{$chunkcount}");
                    $vector = $this->aiclient->embed_query($chunk);
                    $vlength = count($vector);
                    $vectorfield = "solr_vector_" . $vlength;
                    $chunkdoc['id'] = $chunkdoc['id'] . "-chunk-{$i}";
                    $chunkdoc[$vectorfield] = $vector;
                    $filedocs[] = $chunkdoc;
                    $i++;
                }
                $this->logger->info("Generated {$chunkcount} chunks for {$docdata['title']}({$docdata['id']})");
                $docdatabatch[] = $docdata; // Add the "full" item.
                foreach ($filedocs as $filedoc) {
                    $docdatabatch[] = $filedoc;
                }
                $this->logger->info("Added {$chunkcount} filedocs to solr");
            } else {
                $vector = $this->aiclient->embed_query($document['content']);
                $vlength = count($vector);
                $vectorfield = "solr_vector_" . $vlength;
                $this->logger->info("Generated vector length: {length}, field: {field}", [
                        'length' => $vlength, 'field' => $vectorfield
                ]);
                $docdata[$vectorfield] = $vector;
            }
        } else {
            $this->logger->warning("Wasn't able to generate a vector for document");
        }

        if (!$this->add_solr_document($docdata)) {
            $this->logger->warning("Failed to add document to search engine index");
            return false;
        }

        if ($fileindexing) {
            // This will take care of updating all attached files in the index.
            $this->logger->warning("Processing document's files");
            $this->process_document_files($document);
        }

        return true;
    }

    public function add_document_batch(array $documents, bool $fileindexing = false): array {
        $this->logger->info("Entering solrrag::add_document_batch()");
        $docdatabatch = [];
        foreach ($documents as $document) {
            //$docdatabatch[] = $document->export_for_engine();
            $doc = $document->export_for_engine();
            if ($this->aiprovider->use_for_embeddings() && $this->aiclient) {
                if (empty($doc['content'])) {
                    $this->logger->info("Empty doc {id} - {title}", ['id' => $doc['id'], 'title' => $doc['title']]);
                    // We'll still add the meta data.
                    $docdatabatch[] = $doc;
                } else {
                    // TODO 1. Chunk $content in to parts based on some strategy
                    $this->logger->info("Splitting \"{$doc['content']}");
                    $chunks = $this->chunk($doc['content']);
                    $chunkcount = count($chunks);
                    $this->logger->info("{$chunkcount} chunks generated for {$doc['title']}");
                    if ($chunkcount > 1) {
                        // TODO 2. Fetch an embedding for each part.
                        // TODO 3. Add the main $filedoc, as well as each of the chunks, ensuring they
                        // have  a reference to the main $filedoc.
                        $i = 1;
                        foreach ($chunks as $chunk) {
                            $chunkdoc = $doc;
                            $chunkdoc['content'] = $chunk;
                            $this->logger->info("Getting vector for chunk {$i}/{$chunkcount}");
                            $vector = $this->aiclient->embed_query($chunk);
                            $vlength = count($vector);
                            $vectorfield = "solr_vector_" . $vlength;
                            $chunkdoc['id'] = $chunkdoc['id'] . "-chunk-{$i}";
                            $chunkdoc[$vectorfield] = $vector;
                            $filedocs[] = $chunkdoc;
                            $i++;
                        }

                        $this->logger->info("Generated {$chunkcount} chunks for {$doc['title']}({$doc['id']})");
                        $docdatabatch[] = $doc; // Add the "full" item.
                        foreach ($filedocs as $filedoc) {
                            $docdatabatch[] = $filedoc;
                        }
                        $this->logger->info("Added {$chunkcount} filedocs to solr");
                    } else {
                        $this->logger->info('Using full content');
                        $this->logger->info('Generating vector using provider');
                        $vector = $this->aiclient->embed_query($doc['content']);
                        $vlength = count($vector);
                        $vectorfield = "solr_vector_" . $vlength;
                        $doc[$vectorfield] = $vector;
                        $this->logger->info("Vector length {length} field {field}", [
                                'length' => $vlength, 'field' => $vectorfield
                        ]);
                        $docdatabatch[] = $doc;
                    }
                }
            } else {
                    $this->logger->info("Didn't do any vector stuff!");
                    $docdatabatch[] = $doc;
            }
        }
        if (empty($docdatabatch)) {
            echo('no docs');
            //var_dump($documents);
            //exit();
        }
        $resultcounts = $this->add_solr_documents($docdatabatch);

        // Files are processed one document at a time (if there are files it's slow anyway).

        if ($fileindexing) {
            $this->logger->info("Processing files");
            foreach ($documents as $document) {
                // This will take care of updating all attached files in the index.
                $this->process_document_files($document);
            }
            $this->logger->info("Completed Processing files");
        }

        return $resultcounts;
    }
    /**
     * Adds multiple text documents to the search engine.
     *
     * @param array $docs Array of documents (each an array of fields) to add
     * @return int[] Array of success, failure, batch count
     * @throws \core_search\engine_exception
     */
    protected function add_solr_documents(array $docs): array {
        $solrdocs = [];
        foreach ($docs as $doc) {
            //var_dump($doc);
            $solrdoc = $this->create_solr_document($doc);
            //var_dump($solrdoc);
            $solrdocs[] = $solrdoc;
        }
        try {
            // Add documents in a batch and report that they all succeeded.
            $this->get_search_client()->addDocuments($solrdocs, true, static::AUTOCOMMIT_WITHIN);
            return [count($solrdocs), 0, 1];
        } catch (\SolrClientException $e) {
            // If there is an exception, fall through...
            $donothing = true;
        } catch (\SolrServerException $e) {
            // If there is an exception, fall through...
            $donothing = true;
        }

        // When there is an error, we fall back to adding them individually so that we can report
        // which document(s) failed. Since it overwrites, adding the successful ones multiple
        // times won't hurt.
        $success = 0;
        $failure = 0;
        $batches = 0;
        foreach ($docs as $doc) {
            $result = $this->add_solr_document($doc);
            $batches++;
            if ($result) {
                $success++;
            } else {
                $failure++;
            }
        }

        return [$success, $failure, $batches];
    }

    /**
     * Adds a text document to the search engine.
     *
     * @param array $doc
     * @return bool
     */
    protected function add_solr_document($doc) {
        $solrdoc = $this->create_solr_document($doc);

        try {
            $result = $this->get_search_client()->addDocument($solrdoc, true, static::AUTOCOMMIT_WITHIN);
            return true;
        } catch (\SolrClientException $e) {
            debugging('Solr client error adding document with id ' . $doc['id'] . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            $this->logger->error('Solr client error adding document with id ' . $doc['id'] . ': ' . $e->getMessage());
        } catch (\SolrServerException $e) {
            // We only use the first line of the message, as it's a fully java stacktrace behind it.
            // $msg = strtok($e->getMessage(), "\n");
            $msg = $e->getMessage();
            debugging('Solr server error adding document with id ' . $doc['id'] . ': ' . $msg, DEBUG_DEVELOPER);
            $this->logger->error('Solr server error adding document with id ' . $doc['id'] . ': ' . $msg);
            $msgdoc = $doc;
            unset($msgdoc['solr_vector_768']);
            $this->logger->debug(print_r($msgdoc, true));

        }

        return false;
    }

    /**
     * Adds a file to the search engine.
     *
     * Notes about Solr and Tika indexing. We do not send the mime type, only the filename.
     * Tika has much better content type detection than Moodle, and we will have many more doc failures
     * if we try to send mime types.
     *
     * @param \search_solr\document $document
     * @param \stored_file $storedfile
     * @return void
     */
    protected function add_stored_file($document, $storedfile) {
        $this->logger->debug("Entering engine::add_stored_file()");
        $this->logger->info("Adding stored file {name} to document {document}", [
            "name" => $storedfile->get_filename(),
            "document" => "TBD"
        ]);
        $embeddings = [];

        $filedoc = $document->export_file_for_engine($storedfile);
        // Used the underlying implementation

        if (!$this->file_is_indexable($storedfile)) {
            // For files that we don't consider indexable, we will still place a reference in the search engine.
            $this->logger->warning("File {filename} is not indexable", ['filename' => $storedfile->get_filename()]);
            $filedoc['solr_fileindexstatus'] = document::INDEXED_FILE_FALSE;
            $this->add_solr_document($filedoc);
            return;
        }

        $curl = $this->get_curl_object();

        $url = $this->get_connection_url('/update/extract');

        // Return results as XML.
        $url->param('wt', 'xml');

        // This will prevent solr from automatically making fields for every tika output.
        $url->param('uprefix', 'ignored_');

        // Control how content is captured. This will keep our file content clean of non-important metadata.
        $url->param('captureAttr', 'true');
        // Move the content to a field for indexing.
        $url->param('fmap.content', 'solr_filecontent');

        // These are common fields that matches the standard *_point dynamic field and causes an error.
        $url->param('fmap.media_white_point', 'ignored_mwp');
        $url->param('fmap.media_black_point', 'ignored_mbp');

        // Copy each key to the url with literal.
        // We place in a temp name then copy back to the true field, which prevents errors or Tika overwriting common field names.
        foreach ($filedoc as $key => $value) {
            // This will take any fields from tika that match our schema and discard them, so they don't overwrite ours.
            $url->param('fmap.' . $key, 'ignored_' . $key);
            // Place data in a tmp field.
            $url->param('literal.mdltmp_' . $key, $value);
            // Then move to the final field.
            $url->param('fmap.mdltmp_' . $key, $key);
        }

        // This sets the true filename for Tika.
        $url->param('resource.name', $storedfile->get_filename());
        // If we're not doing embeddings, then we can just use the "original" implementation which will
        // extract and index the file without passing the content back.
        if ($this->aiprovider->use_for_embeddings()) {
            $this->logger->info("Extracting file content without embeddings");
            $url->param('extractOnly', "true"); // This gets solr to extract the content but not write it to the index.
        }

        // A giant block of code that is really just error checking around the curl request.
        try {
            $requesturl = $url->out(false);

            $this->logger->info("Attempting to extract resource content");
            // We have to post the file directly in binary data (not using multipart) to avoid
            // Solr bug SOLR-15039 which can cause incorrect data when you use multipart upload.
            // Note this loads the whole file into memory; see limit in file_is_indexable().
            $result = $curl->post($requesturl, $storedfile->get_content());

            $code = $curl->get_errno();
            $info = $curl->get_info();

            // Now error handling. It is just informational, since we aren't tracking per file/doc results.
            if ($code != 0) {
                // This means an internal cURL error occurred error is in result.
                $message = 'Curl error ' . $code . ' while indexing file with document id ' . $filedoc['id'] . ': ' . $result . '.';
                debugging($message, DEBUG_DEVELOPER);
                $this->logger->error($message);
            } else if (isset($info['http_code']) && ($info['http_code'] !== 200)) {
                // Unexpected HTTP response code.
                $message = 'Error while indexing file with document id ' . $filedoc['id'];
                // Try to get error message out of msg or title if it exists.
                if (preg_match('|<str [^>]*name="msg"[^>]*>(.*?)</str>|i', $result, $matches)) {
                    $message .= ': ' . $matches[1];
                } else if (preg_match('|<title[^>]*>([^>]*)</title>|i', $result, $matches)) {
                    $message .= ': ' . $matches[1];
                }
                // This is a common error, happening whenever a file fails to index for any reason, so we will make it quieter.
                if (CLI_SCRIPT && !PHPUNIT_TEST) {
                    mtrace($message);
                    $this->logger->warning($message);
                    if (debugging()) {
                        $this->logger->debug($requesturl);
                    }
                    // Suspiciion that this fails due to the file contents being PDFs.
                }
            } else {
                // Check for the expected status field.
                if (preg_match('|<int [^>]*name="status"[^>]*>(\d*)</int>|i', $result, $matches)) {
                    // Now check for the expected status of 0, if not, error.
                    if ((int)$matches[1] !== 0) {
                        $message = 'Unexpected Solr status code ' . (int)$matches[1];
                        $message .= ' while indexing file with document id ' . $filedoc['id'] . '.';
                        $this->logger->warning($message);
                    } else {
                        // The document was successfully extracted.
                        if ($this->aiprovider->use_for_embeddings() && $this->aiclient) {
                            $matchresult = preg_match('/<str>(?<Content>.*)<\/str>/imsU', $result, $streamcontent);
                            if ($matchresult === 0) {
                                $this->logger->error("Didn't get an extraction response");
                                $filedoc['solr_fileindexstatus'] = document::INDEXED_FILE_ERROR;
                                $this->logger->debug($requesturl);
                                $this->logger->debug($result);

                            } else {
                                $filedocs = []; // We could end up with multiple filedocs if the content is large and needs to be chunked.
                                $this->logger->info('document extracted successfully');
                                $xmlcontent = html_entity_decode($streamcontent[1]);
                                $this->logger->debug($xmlcontent);
                                try {
                                    $xml = simplexml_load_string($xmlcontent);
                                    if ($xml === false) {
                                        $this->logger->error("Didn't get back a valid XML response");
                                        $filedoc['solr_fileindexstatus'] = document::INDEXED_FILE_ERROR;
                                    } else {
                                        $filedoc['content'] = (string)$xml->body->asXML();
                                        $metadata = $xml->head->meta;
                                        foreach ($metadata as $meta) {
                                            $name = (string)$meta['name'];
                                            $content = (string)$meta['content'];
                                            if ($content != null) {
                                                $filedoc[$name] = $content;
                                            } else {
                                                $filedoc[$name] = "";
                                            }
                                        }
                                        // Note a successful extraction in the log
                                        $this->logger->info("Successfully extracted content from file {filename}", [
                                            'filename' => $storedfile->get_filename()
                                        ]);
                                    }
                                    /**
                                     * Since solr has given us back the content, we can now send it off to the AI provider.
                                     */
                                    // garnish $filedoc with the embedding vector. It would be nice if this could be done
                                    // via the export_file_for_engine() call above, that has no awareness of the engine.
                                    // We expect $filedoc['content'] to be set.
                                    if(isset($filedoc['content'])) {
                                        $this->logger->info("Processing stored file content");
                                        // TODO 1. Chunk $content in to parts based on some strategy
                                        $contextlength = self::CHUNK_SIZE;
                                                //$this->aiprovider->get('aimaxcontext');
                                        $contentlength = strlen($filedoc['content']);
                                        $this->logger->info("Content length: {$contentlength} - {$filedoc['title']}" );
                                        if ($contentlength > $contextlength) {
                                            $this->logger->warning("{$filedoc['title']} is longer ({$contentlength}) that context window ({$contextlength})");
                                        }

                                        $this->logger->info("Splitting stored file content");   // Removed actual content to constrain size.
                                        $chunks = $this->chunk($filedoc['content']);
                                        $chunkcount = count($chunks);
                                        $this->logger->info("Split {$filedoc['title']} in to {$chunkcount} chunks.");
                                        if ($chunkcount > 1) {
                                            $this->logger->info("Processing {$filedoc['title']} chunks.");
                                            // TODO 2. Fetch an embedding for each part.
                                            // TODO 3. Add the main $filedoc, as well as each of the chunks, ensuring they
                                            // have  a reference to the main $filedoc.
                                            $i = 1;
                                            foreach($chunks as $chunk) {
                                                $chunkdoc = $filedoc;
                                                $chunkdoc['content'] = $chunk;
                                                $this->logger->info("Getting vector for chunk {$i}/{$chunkcount}");
                                                $vector = $this->aiclient->embed_query($chunk);
                                                $vlength = count($vector);
                                                $vectorfield = "solr_vector_" . $vlength;
                                                $chunkdoc[$vectorfield] = $vector;
                                                $chunkdoc['id'] = $chunkdoc['id'] . "-chunk-{$i}";
                                                $filedocs[] = $chunkdoc;
                                                $i++;
                                            }

                                            $this->logger->info("Generated {$chunkcount} chunks for stored file {$filedoc['title']}");
                                            foreach($filedocs as $filedoc) {
                                                $this->add_solr_document($filedoc);
                                            }
                                            $this->logger->info("Added {$chunkcount} filedocs to solr");
                                            // We don't return here, so that the "main" $filedoc also gets added.
                                        } else {
                                            $this->logger->info("Processing {$filedoc['title']} whole content.");
                                            // This handles a chunk count of 1 or 0. 0 would be very odd!
                                            // Either way we can just store the content.
                                            $vector = $this->aiclient->embed_query($filedoc['content']);
                                            $vlength = count($vector);
                                            $vectorfield = "solr_vector_" . $vlength;
                                            $filedoc[$vectorfield] = $vector;
                                            $this->logger->info("Generated vector length: {length}, field: {field}", [
                                                    'length' => $vlength, 'field' => $vectorfield
                                            ]);
                                        }
                                    } else {
                                        $this->logger->info("Document had no content", $filedoc);
                                    }
                                    $this->logger->info("Solr dor: {doc}", ["doc" => print_r($filedoc,true)]);
                                } catch (\Exception $e) {
                                    $this->logger->error("Error parsing XML from solr");
                                    //$this->logger->debug($xmlcontent);
                                }
                            }
                            // We can add either the document with content or without.
                            $this->logger->info("Adding document to search index.");
                            $this->add_solr_document($filedoc);
                            return;
                        } else {
                            // We can add either the document with content or without.
                            $this->logger->info("Adding document to search index.");
                            $this->add_solr_document($filedoc);
                            return;
                        }
                    }
                } else {
                    // We received an unprocessable response.
                    $message = 'Unexpected Solr response while indexing file with document id ' . $filedoc['id'] . ': ';
                    $message .= strtok($result, "\n");
                    debugging($message, DEBUG_DEVELOPER);
                    $this->logger->warning($message);
                }
            }
        } catch (\Exception $e) {
            // There was an error, but we are not tracking per-file success, so we just continue on.
            debugging('Unknown exception while indexing file "' . $storedfile->get_filename() . '".', DEBUG_DEVELOPER);
            $this->logger->error($message);
        }
        
        // If we get here, the document was not indexed due to an error. So we will index just the base info without the file.
        $filedoc['solr_fileindexstatus'] = document::INDEXED_FILE_ERROR;

        $this->add_solr_document($filedoc);


        // It would have been nice to use the underlying solr code, but its too tightly integrated
        // with talking to solr.
        //return parent::add_stored_file($document, $storedfile);
    }


    protected function create_solr_document(array $doc): \SolrInputDocument {
        $solrdoc = new \SolrInputDocument();

        $forcetostring = ["dc_title", "Object_Name"];
        // Replace underlines in the content with spaces. The reason for this is that for italic
        // text, content_to_text puts _italic_ underlines. Solr treats underlines as part of the
        // word, which means that if you search for a word in italic then you can't find it.
        if (array_key_exists('content', $doc)) {
            $doc['content'] = self::replace_underlines($doc['content']);
        }

        // Set all the fields.
        foreach ($doc as $field => $value) {
            if (is_null($value)) {
                continue;
            }
            if (is_array($value)) {
                $i = 0;
                foreach ($value as $v) {
                    if (empty($v)) {
                        $this->logger->debug("Field {name} pos {i} is empty", ["name" => $field, "i" => $i]);
                    }
                    $solrdoc->addField($field, $v);
                    $i++;
                }
                continue;
            }
            if (empty($value)) {
                $this->logger->debug("Field {name} is empty", ["name" => $field]);
            }
            if (in_array($field, $forcetostring)) {
                $this->logger->debug("Forcing {name} to string", ["name" => $field]);
                $value = "{$value}";
            }
            $solrdoc->addField($field, $value);
        }
        // We need to consider that the content is bigger than the AI's context window.
        $contextlength = $this->aiprovider->get('aimaxcontext');
        $contentlength = strlen($doc['content']);
        if ($contentlength > $contextlength) {
            $title = $doc['title'] ?? "-";
            $this->logger->warning("{$title} is longer ({$contentlength}) that context window ({$contextlength})");
            // TODO we'll have to worry about this in a bit.
        }

        return $solrdoc;
    }

    /**
     * @param $filters \stdClass
     * @param $accessinfo
     * @param $limit
     * @return void
     * @throws \core_search\engine_exception
     */
    public function execute_query($filters, $accessinfo, $limit = 0) {
        $this->logger->info("Entering execute_query");
        if (isset($filters->similarity) &&
            $filters->similarity
        ) {
            // Do a vector similarity search.
            $this->logger->info("Running similarity search");
            $this->logger->info("Fetching Vector for \"{userquery}\"", (array)$filters);
            $vector = $this->aiclient->embed_query($filters->userquery);
            $filters->vector = $vector;
            // We may get accessinfo, but we actually should determine our own ones to apply too
            // But we can't access the "manager" class' get_areas_user_accesses function, and
            // that's already been called based on the configuration / data from the user
            $docs = $this->execute_similarity_query($filters, $accessinfo, $limit);
            // Really should run a process similar to the process_response() function.

            return $docs;
        } else {
            $this->logger->info("Executing regular search");
            return parent::execute_query($filters, $accessinfo, $limit);
        }
    }

    /**
     * Perform a similarity search against the backend.
     * 
     * This should be an optional method that can be implemented if the engine supports
     * a vector search capability.
     * 
     * This function will broadly replicate the same functionality as execute_query, but optimised 
     * for similarity
     * 
     * @param \stdClass filters The filters object that contains the query and any other parameters. Basically from the search form.
     * @param \stdClass accessinfo The access information for the user.
     * @param int limit The maximum number of results to return.
     */
    public function execute_similarity_query(\stdClass $filters, \stdClass $accessinfo, int $limit = null) {
        $data = clone($filters);
        $returnemptydocs = $filters->returnemptydocs ?? false;
        $this->logger->info("Executing SOLR KNN QUery");
        $vector = $filters->vector;
        if (empty($vector)) {
            throw new \coding_exception("Vector cannot be empty!");
        }
        $topK = $limit > 0 ? $limit: 1; // We'll make the number of neighbours the same as search result limit.

        if (empty($limit)) {
            $limit = \core_search\manager::MAX_RESULTS;
            $topK = \core_search\manager::MAX_RESULTS;  // Nearest neighbours to retrieve.
        }

        $field = "solr_vector_" . count($vector);
        $requestbody = "{!knn f={$field} topK={$topK}}[" . implode(",", $vector) . "]";

        $filters->mainquery = $requestbody;
        // Build filter restrictions.
        $filterqueries = [];
        if(!empty($data->areaids)) {
            $r = '{!cache=false}areaid:(' . implode(' OR ', $data->areaids) . ')';
            $this->logger->info("Attaching areaid restriction: {areaid}", ['areaid' => $r]);
            $filterqueries[] = $r;
        }
        $r = null;
        if(!empty($data->excludeareaids)) {
            $r ='{!cache=false}-areaid:(' . implode(' OR ', $data->excludeareaids) . ')';
            $this->logger->info("Attaching areaid restriction: {areaid}", ['areaid' => $r]);
            $filterqueries[] = $r;
        }
        $r = null;
        // Build access restrictions.

        // And finally restrict it to the context where the user can access, we want this one cached.
        // If the user can access all contexts $usercontexts value is just true, we don't need to filter
        // in that case.
        if (!$accessinfo->everything && is_array($accessinfo->usercontexts)) {
            // Join all area contexts into a single array and implode.
            $allcontexts = array();
            foreach ($accessinfo->usercontexts as $areaid => $areacontexts) {
                if (!empty($data->areaids) && !in_array($areaid, $data->areaids)) {
                    // Skip unused areas.
                    continue;
                }
                foreach ($areacontexts as $contextid) {
                    // Ensure they are unique.
                    $allcontexts[$contextid] = $contextid;
                }
            }
            if (empty($allcontexts)) {
                $this->logger->warning("User has no contexts at all");
                // This means there are no valid contexts for them, so they get no results.
                return null;
            }
            $contexts ='contextid:(' . implode(' OR ', $allcontexts) . ')';
            $this->logger->info("Attaching context restriction: {contexts}", ['contexts' => $contexts]);
            $filterqueries[] = $contexts;
        }
        $r = null;
        if (!$accessinfo->everything && $accessinfo->separategroupscontexts) {
            // Add another restriction to handle group ids. If there are any contexts using separate
            // groups, then results in that context will not show unless you belong to the group.
            // (Note: Access all groups is taken care of earlier, when computing these arrays.)

            // This special exceptions list allows for particularly pig-headed developers to create
            // multiple search areas within the same module, where one of them uses separate
            // groups and the other uses visible groups. It is a little inefficient, but this should
            // be rare.
            $exceptions = '';
            if ($accessinfo->visiblegroupscontextsareas) {
                foreach ($accessinfo->visiblegroupscontextsareas as $contextid => $areaids) {
                    $exceptions .= ' OR (contextid:' . $contextid . ' AND areaid:(' .
                            implode(' OR ', $areaids) . '))';
                }
            }

            if ($accessinfo->usergroups) {
                // Either the document has no groupid, or the groupid is one that the user
                // belongs to, or the context is not one of the separate groups contexts.
                $r = '(*:* -groupid:[* TO *]) OR ' .
                        'groupid:(' . implode(' OR ', $accessinfo->usergroups) . ') OR ' .
                        '(*:* -contextid:(' . implode(' OR ', $accessinfo->separategroupscontexts) . '))' .
                        $exceptions;
                $this->logger->info("attaching usergroup restriction: {usergroups}", ['usergroups' => $r]);
                $filterqueries[] = $r;
            } else {
                // Either the document has no groupid, or the context is not a restricted one.
                $r = '(*:* -groupid:[* TO *]) OR ' .
                        '(*:* -contextid:(' . implode(' OR ', $accessinfo->separategroupscontexts) . '))' .
                        $exceptions;
                $this->logger->info("attaching usergroup restriction: {usergroups}", ['usergroups' => $r]);
                $filterqueries[] = $r;
            }
        }

        $params = [
            "query" => $requestbody,
        ];
        // Query String parameters.
        $qsparams = [];

        if ($this->file_indexing_enabled()) {
            // Now group records by solr_filegroupingid. Limit to 3 results per group.
            // TODO work out how to convert the following into query / filter parameters.#
            $this->logger->info("Setting SOLR group parameters");
            $qsparams['group'] = "true";
            $qsparams['group.limit'] = 3;
            $qsparams['group.ngroups'] = "true";
            $qsparams['group.field'] = 'solr_filegroupingid';
        } else {
            // Make sure we only get text files, in case the index has pre-existing files.
            $filterqueries[] = 'type:'.\core_search\manager::TYPE_TEXT;
        }

        // Finally perform the actual search.

        $curl = $this->get_curl_object();
        $requesturl = $this->get_connection_url('/select');
//        $requesturl->param('fl', 'id,areaid,score,content, title');
        $requesturl->param('fl', '*,score');
        // Title is added on the end so we didn't have to recode some indexes below.
        $requesturl->param('wt', 'xml');
        foreach($qsparams as $qs => $value) {
            $requesturl->param($qs, $value);
        }
        foreach($filterqueries as $fq) {
            $requesturl->param('fq', $fq);
        }

        $curl->setHeader('Content-type: application/json');
        $this->logger->info("Solr request: ".$requesturl->out(false));
        $logparams = $params;
        //unset($logparams['query']); // unset query as it's got the full vector in it.
        $this->logger->info("Solr request params: ". json_encode($logparams));
        $result = $curl->post($requesturl->out(false), json_encode($params));
        $this->logger->info("Got SOLR result");
$this->logger->debug($result);
        // Probably have to duplicate error handling code from the add_stored_file() function.
        $code = $curl->get_errno();
        $info = $curl->get_info();
        // Now error handling. It is just informational, since we aren't tracking per file/doc results.
        if ($code != 0) {
            // This means an internal cURL error occurred error is in result.
            $message = 'Curl error ' . $code . ' retrieving';
//                . $filedoc['id'] . ': ' . $result . '.';
            debugging($message, DEBUG_DEVELOPER);
            $this->logger->error($message);
        } else if (isset($info['http_code']) && ($info['http_code'] !== 200)) {
            // Unexpected HTTP response code.
            $message = 'Error while querying for documents ' ;
            // Try to get error message out of msg or title if it exists.
            if (preg_match('|<str [^>]*name="msg"[^>]*>(.*?)</str>|i', $result, $matches)) {
                $message .= ': ' . $matches[1];
            } else if (preg_match('|<title[^>]*>([^>]*)</title>|i', $result, $matches)) {
                $message .= ': ' . $matches[1];
            }
            // This is a common error, happening whenever a file fails to index for any reason, so we will make it quieter.
            if (CLI_SCRIPT && !PHPUNIT_TEST) {
                mtrace($message);
                $this->logger->warning($message);
                if (debugging()) {
                    mtrace($requesturl);
                    $this->logger->info($requesturl);
                }
                // Suspiciion that this fails due to the file contents being PDFs.
            }
        } else {
            // Check for the expected status field.

            if (preg_match('|<int [^>]*name="status"[^>]*>(\d*)</int>|i', $result, $matches)) {
                // Now check for the expected status of 0, if not, error.
                if ((int)$matches[1] !== 0) {
                    $message = 'Unexpected Solr status code ' . (int)$matches[1];
                    $this->logger->warning($message);
                } else {
                    $this->logger->info("Parsing solr result");
                    // We got a result back.
//                    echo htmlentities($result);
//                    debugging("Got SOLR update/extract response");
                    $xml = simplexml_load_string($result);
                    if ($this->file_indexing_enabled()) {
                        $this->logger->info("File indexing enabled");
                        // We'll just grab all of the <doc> elements that were found.
                        $results = $xml->xpath("//doc");
                        //$this->logger->debug(print_r($results, true));
                    } else {
                        $results = $xml->result->doc;
                        //$this->logger->debug($result);
                    }
                    $docs = [];
                    $titles = [];
                    $contextlength = $this->aiprovider->get('aimaxcontext');
                    if (!empty($results)) {
//                        echo "<pre>";
                        foreach ($results as $result) {
                            $result->rewind();
                            $doc = [];
                            while($result->valid()) {
                                $element = $result->current();
                                $name = (string)$element["name"];
                                $doc[$name] = trim((string)$element);
                                $result->next();
                            }
                            $this->logger->debug("Outputting similarity search results");
                            $this->logger->debug(print_r($doc, true));

                            $searcharea = $this->get_search_area($doc['areaid']);
                            $titles[] = $doc['title'];

                            $score = $doc['score'] ?? null;
                            $this->logger->info("{$doc['title']}score: {$score}");
                            $doc = $this->to_document($searcharea, $doc);

                            // we're now a "Document" object, so check for content.
                            if ($doc->is_set('content')) {
                                // Drop content > context length...it's rough but...
                                $contentlength = strlen($doc->get('content'));
                                if ($contentlength > $contextlength) {
                                    // TODO We need a better strategy, but we'll work within what we have with SOLR and global search.
                                    $this->logger->warning("Dropping {$doc['title']} as it is larger than the context ({$contentlength} vs {$contextlength}");
                                    continue;
                                }
                                $docs[] = $doc;
                            } else {
                                if ($returnemptydocs) {
                                    $docs[] = $doc;
                                }
                                $this->logger->info("Document {title} had no content in the end", ['title' => $doc->get('title')]);
                            }
                        }
//                        echo "</pre>";
                        // Just for audit/debugging we output the list of resource titles.
                        $this->logger->info("Document titles: {titles}", ['titles'=> implode(",", $titles)]);
                        foreach ($docs as $doc) {
                            $this->logger->info($doc->get('content'));
                        }
                    } else {
                        $this->logger->info("No results found");
                    }

                    return $docs;

                }
            } else {
                // We received an unprocessable response.
                $message = 'Unexpected Solr response';
                $message .= strtok($result, "\n");
                debugging($message, DEBUG_DEVELOPER);
                $this->logger->warning($message);
            }
        }
        return [];
    }

    /**
     * @see \search_solr\engine::get_schema_version()
     *
     * @param $oldversion
     * @param $newversion
     * @return bool|\lang_string|string
     * @throws \coding_exception
     * @throws \moodle_exception
     */
    protected function update_schema($oldversion, $newversion) {
        // Construct schema.
        $schema = new \search_solrrag\schema($this);
        $cansetup = $schema->can_setup_server();
        if ($cansetup !== true) {
            return $cansetup;
        }

        switch ($newversion) {
            // This version just requires a setup call to add new fields.
            case 2017091700:
                $setup = true;
                break;

            // If we don't know about the schema version we might not have implemented the
            // change correctly, so return.
            default:
                return get_string('schemaversionunknown', 'search');
        }

        if ($setup) {
            $schema->setup();
        }

        return true;
    }
    /**
     * Index files attached to the docuemnt, ensuring the index matches the current document files.
     *
     * For documents that aren't known to be new, we check the index for existing files.
     * - New files we will add.
     * - Existing and unchanged files we will skip.
     * - File that are in the index but not on the document will be deleted from the index.
     * - Files that have changed will be re-indexed.
     *
     * @param \search_solr\document $document
     */
    protected function process_document_files($document) {
        if (!$this->file_indexing_enabled()) {
            return;
        }

        // Maximum rows to process at a time.
        $rows = 500;

        // Get the attached files.
        $files = $document->get_files();

        // If this isn't a new document, we need to check the exiting indexed files.
        if (!$document->get_is_new()) {
            // We do this progressively, so we can handle lots of files cleanly.
            list($numfound, $indexedfiles) = $this->get_indexed_files($document, 0, $rows);
            $count = 0;
            $idstodelete = array();

            do {
                // Go through each indexed file. We want to not index any stored and unchanged ones, delete any missing ones.
                foreach ($indexedfiles as $indexedfile) {
                    $fileid = $indexedfile->solr_fileid;

                    if (isset($files[$fileid])) {
                        // Check for changes that would mean we need to re-index the file. If so, just leave in $files.
                        // Filelib does not guarantee time modified is updated, so we will check important values.
                        if ($indexedfile->modified != $files[$fileid]->get_timemodified()) {
                            continue;
                        }
                        if (strcmp($indexedfile->title, $files[$fileid]->get_filename()) !== 0) {
                            continue;
                        }
                        if ($indexedfile->solr_filecontenthash != $files[$fileid]->get_contenthash()) {
                            continue;
                        }
                        if ($indexedfile->solr_fileindexstatus == document::INDEXED_FILE_FALSE &&
                            $this->file_is_indexable($files[$fileid])) {
                            // This means that the last time we indexed this file, filtering blocked it.
                            // Current settings say it is indexable, so we will allow it to be indexed.
                            continue;
                        }

                        // If the file is already indexed, we can just remove it from the files array and skip it.
                        unset($files[$fileid]);
                    } else {
                        // This means we have found a file that is no longer attached, so we need to delete from the index.
                        // We do it later, since this is progressive, and it could reorder results.
                        $idstodelete[] = $indexedfile->id;
                    }
                }
                $count += $rows;

                if ($count < $numfound) {
                    // If we haven't hit the total count yet, fetch the next batch.
                    list($numfound, $indexedfiles) = $this->get_indexed_files($document, $count, $rows);
                }

            } while ($count < $numfound);

            // Delete files that are no longer attached.
            foreach ($idstodelete as $id) {
                // We directly delete the item using the client, as the engine delete_by_id won't work on file docs.
                $this->get_search_client()->deleteById($id);
            }
        }

        // Now we can actually index all the remaining files.
        foreach ($files as $file) {
            $this->add_stored_file($document, $file);
        }
    }

    /**
     * Arbitrary chunk size.
     */
    const CHUNK_SIZE = 2048;
    /**
     * Split the content and return an array of content bits
     * @param $content
     * @return array
     */
    public function chunk($content) {
        $chunks = str_split($content, engine::CHUNK_SIZE);
        //$chunks = mb_str_split($content, engine::CHUNK_SIZE);
        $this->logger->debug(print_r($chunks, true));
        return $chunks;
    }
}
