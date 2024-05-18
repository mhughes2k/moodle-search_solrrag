<?php

namespace search_solrrag;

use search_solr\document;
use \search_solrrag\engine;

class schema extends \search_solr\schema
{
    public function __construct(engine $engine = null)
    {
        if (!$this->config = get_config('search_solrrag')) {
            throw new \moodle_exception('missingconfig', 'search_solrrag');
        }

        if (empty($this->config->server_hostname) || empty($this->config->indexname)) {
            throw new \moodle_exception('missingconfig', 'search_solrrag');
        }

        $this->engine = $engine ?? new engine();
        $this->curl = $this->engine->get_curl_object();

        // HTTP headers.
        $this->curl->setHeader('Content-type: application/json');
    }

    public function validate_setup() {
        $fields = \search_solrrag\document::get_default_fields_definition();

        // Field id is already there.
        unset($fields['id']);

        $this->check_index();
        $this->validate_fields($fields, true);
    }

    /**
     * Checks if the schema existing fields are properly set, triggers an exception otherwise.
     *
     * @throws \moodle_exception
     * @param array $fields
     * @param bool $requireexisting Require the fields to exist, otherwise exception.
     * @return void
     */
    protected function validate_fields(&$fields, $requireexisting = false) {
        global $CFG;
        foreach ($fields as $fieldname => $data) {
            $url = $this->engine->get_connection_url('/schema/fields/' . $fieldname);
            $results = $this->curl->get($url);

            if ($this->curl->error) {
                throw new \moodle_exception('errorcreatingschema', 'search_solrrag', '', $this->curl->error);
            }

            if (!$results) {
                throw new \moodle_exception('errorcreatingschema', 'search_solrrag', '', get_string('nodatafromserver', 'search_solrrag'));
            }
            $results = json_decode($results);

            if ($requireexisting && !empty($results->error) && $results->error->code === 404) {
                $a = new \stdClass();
                $a->fieldname = $fieldname;
                $a->setupurl = $CFG->wwwroot . '/search/engine/solrrag/setup_schema.php';
                throw new \moodle_exception('errorvalidatingschema', 'search_solrrag', '', $a);
            }

            // The field should not exist so we only accept 404 errors.
            if (empty($results->error) || (!empty($results->error) && $results->error->code !== 404)) {
                if (!empty($results->error)) {
                    throw new \moodle_exception('errorcreatingschema', 'search_solrrag', '', $results->error->msg);
                } else {
                    // All these field attributes are set when fields are added through this script and should
                    // be returned and match the defined field's values.

                    $expectedsolrfield = $this->doc_field_to_solr_field($data['type']);
                    if (empty($results->field) || !isset($results->field->type) ||
                        !isset($results->field->multiValued) || !isset($results->field->indexed) ||
                        !isset($results->field->stored)) {

                        throw new \moodle_exception('errorcreatingschema', 'search_solrrag', '',
                            get_string('schemafieldautocreated', 'search_solrrag', $fieldname));

                    } else if ($results->field->type !== $expectedsolrfield ||
                        $results->field->multiValued !== false ||
                        $results->field->indexed !== $data['indexed'] ||
                        $results->field->stored !== $data['stored']) {

                        throw new \moodle_exception('errorcreatingschema', 'search_solrrag', '',
                            get_string('schemafieldautocreated', 'search_solrrag', $fieldname));
                    } else {
                        // The field already exists and it is properly defined, no need to create it.
                        unset($fields[$fieldname]);
                    }
                }
            }
        }
    }
    public function setup($checkexisting = true) {
        $fields = \search_solrrag\document::get_default_fields_definition();

        // Field id is already there.
        unset($fields['id']);

        $this->check_index();

        $return = $this->add_fields($fields, $checkexisting);

        // Tell the engine we are now using the latest schema version.
        $this->engine->record_applied_schema_version(document::SCHEMA_VERSION);

        return $return;
    }
    protected function validate_add_field_result($result) {

        if (!$result) {
            throw new \moodle_exception('errorcreatingschema', 'search_solrrag', '', get_string('nodatafromserver', 'search_solrrag'));
        }

        $results = json_decode($result);
        if (!$results) {
            if (is_scalar($result)) {
                $errormsg = $result;
            } else {
                $errormsg = json_encode($result);
            }
            throw new \moodle_exception('errorcreatingschema', 'search_solrrag', '', $errormsg);
        }

        // It comes as error when fetching fields data.
        if (!empty($results->error)) {
            var_dump($results);
            throw new \moodle_exception('errorcreatingschema', 'search_solrrag', '', $results->error);
        }

        // It comes as errors when adding fields.
        if (!empty($results->errors)) {

            // We treat this error separately.
            $errorstr = '';
            foreach ($results->errors as $error) {
                $errorstr .= implode(', ', $error->errorMessages);
            }
            throw new \moodle_exception('errorcreatingschema', 'search_solrrag', '', $errorstr);
        }

    }
//    public function can_setup_server() {
//print_r($this->engine);
//        $status = $this->engine->is_server_configured();
//        if ($status !== true) {
//            return $status;
//        }
//
//        // At this stage we know that the server is properly configured with a valid host:port and indexname.
//        // We're not too concerned about repeating the SolrClient::system() call (already called in
//        // is_server_configured) because this is just a setup script.
//        if ($this->engine->get_solr_major_version() < 5) {
//            // Schema setup script only available for 5.0 onwards.
//            return get_string('schemasetupfromsolr5', 'search_solr');
//        }
//
//        return true;
//    }
}
