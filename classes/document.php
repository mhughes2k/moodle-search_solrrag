<?php
namespace search_solrrag;

class document extends \search_solr\document {
    protected static $enginefields = array(
        'solr_filegroupingid' => array(
            'type' => 'string',
            'stored' => true,
            'indexed' => true
        ),
        'solr_fileid' => array(
            'type' => 'string',
            'stored' => true,
            'indexed' => true
        ),
        'solr_filecontenthash' => array(
            'type' => 'string',
            'stored' => true,
            'indexed' => true
        ),
        // Stores the status of file indexing.
        'solr_fileindexstatus' => array(
            'type' => 'int',
            'stored' => true,
            'indexed' => true
        ),
        // Field to index, but not store, file contents.
        'solr_filecontent' => array(
            'type' => 'text',
            'stored' => false,
            'indexed' => true,
            'mainquery' => true
        ),
        'solr_vector_1536' => [
            'type' => 'knn_vector_1536', // this field def seems to be related to the size of the LLM embedding too :-(
            'stored' => true,
            'indexed' => true
        ],
        'solr_vector_3072' => [
            'type' => 'knn_vector_3072', // this field def seems to be related to the size of the LLM embedding too :-(
            'stored' => true,
            'indexed' => true
        ],
        'solr_vector_768' => [
            'type' => 'knn_vector_768', // this field def seems to be related to the size of the LLM embedding too :-(
            'stored' => true,
            'indexed' => true
        ],
        // This is a fix for indexing so that we're not trying
        // change from a NONE to a String.
        'dc_title' => [
            'type' => 'string',
            'stored' => true,
            'indexed' => true
        ],

    );

    /**
     * Export the data for the given file in relation to this document.
     *
     * @param \stored_file $file The stored file we are talking about.
     * @return array
     */
    public function export_file_for_engine($file) {
        $data = $this->export_for_engine();

        // Content is index in the main document.
        unset($data['content']);
        unset($data['description1']);
        unset($data['description2']);

        // Going to append the fileid to give it a unique id.
        $data['id'] = $data['id'].'-solrfile'.$file->get_id();
        $data['type'] = \core_search\manager::TYPE_FILE;
        $data['solr_fileid'] = $file->get_id();
        $data['solr_filecontenthash'] = $file->get_contenthash();
        $data['solr_fileindexstatus'] = self::INDEXED_FILE_TRUE;
        $data['solr_vector'] = null;
        $data['title'] = $file->get_filename();
        $data['modified'] = self::format_time_for_engine($file->get_timemodified());

        return $data;
    }

    /**
     * Returns the "content" of the documents for embedding.
     * This may use some sort of external system.
     * @return void
     */
    public function fetch_document_contents() {

    }
    public function set_data_from_engine($docdata) {
        $fields = static::$requiredfields + static::$optionalfields + static::$enginefields;
        $skipfields = [
            'solr_vector_1536',
            'solr_vector_3072',
            'solr_vector_768'
        ];
        foreach ($fields as $fieldname => $field) {

            // Optional params might not be there.
            if (isset($docdata[$fieldname])) {
                if ($field['type'] === 'tdate') {
                    // Time fields may need a preprocessing.
                    $this->set($fieldname, static::import_time_from_engine($docdata[$fieldname]));
                } else {
                    // No way we can make this work if there is any multivalue field.
//                    if($fieldname === 'solr_vector_1536' || $fieldname === 'solr_vector_3072') {
                    if (in_array($fieldname, $skipfields)) {
//                        debugging("Skipping $fieldname");
                        continue;
                    }
                    if (is_array($docdata[$fieldname])) {
                        throw new \core_search\engine_exception('multivaluedfield', 'search_solr', '', $fieldname);
                    }
                    $this->set($fieldname, $docdata[$fieldname]);
                }
            }
        }
    }
}
