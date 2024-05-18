<?php
// We're mocking a core Moodle "AI" Subsystem a la Oauth 2

namespace core\ai;

require_once($CFG->dirroot . "/search/engine/solrrag/lib.php");

class api {

    /**
     * Return a list of AIProviders that are available for specified context.
     * @param $context
     * @return array
     */
    public static function get_all_providers($context = null) {
        return array_values(AIProvider::get_records());
    }
    public static function get_provider(int $id): AIProvider {
        $fakes = AIProvider::get_records();
        return $fakes[0]; // Open AI
        // return $fakes[1]; // Ollama

    }
}
