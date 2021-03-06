<?php
/**
 * ElaticSearch query model
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Smile Searchandising Suite to newer
 * versions in the future.
 *
 * This work is a fork of Johann Reinke <johann@bubblecode.net> previous module
 * available at https://github.com/jreinke/magento-elasticsearch
 *
 * @category  Smile
 * @package   Smile_ElasticSearch
 * @author    Aurelien FOUCRET <aurelien.foucret@smile.fr>
 * @copyright 2013 Smile
 * @license   Apache License Version 2.0
 */
class Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Query_Fulltext
    extends Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Query_Abstract
{

    /**
     * Build the fulltext query condition for the query.
     *
     * @return array
     */
    protected function _prepareFulltextCondition()
    {
        $query = array('match_all' => array());

        if ($this->_fulltextQuery && is_string($this->_fulltextQuery)) {

            $queryText = $this->prepareFilterQueryText($this->_fulltextQuery);
            $query = array('bool' => array('disable_coord' => true));
            $searchFields = $this->getSearchFields();

            $spellingParts = $this->getSpellingParts($queryText, $searchFields);

            if (isset($spellingParts['matched']) && !empty($spellingParts['matched'])) {
                $queryText = implode(' ', $spellingParts['matched']);
                $query['bool']['must'][] = $this->getExactMatchesQuery($queryText, $searchFields);
            }


            if (isset($spellingParts['unmatched']) && !empty($spellingParts['unmatched'])) {
                foreach ($spellingParts['unmatched'] as $fuzzyQueryText) {
                    $query['bool']['must'][] = $this->getFuzzyMatchesQuery($fuzzyQueryText, $searchFields);
                }
            }

            $this->_fulltextQuery = $query;
        } else if (is_array($this->_fulltextQuery)) {
            $query = $this->_fulltextQuery;
        }

        return $query;
    }

    /**
     * Retrieve the spelling part of a query through self::_analyzeQuerySpelling
     *
     * @param string $queryText    Text to be searched.
     * @param array  $searchFields Search fields configuration.
     *
     * @return array
     */
    public function getSpellingParts($queryText, $searchFields)
    {
        $hasFuzzyFields = false;

        foreach ($searchFields as $fieldName => $currentField) {
            if ($currentField['fuzziness'] !== false) {
                $hasFuzzyFields = true;
            }
        }

        if ($hasFuzzyFields === true) {
            $spellingParts = $this->_analyzeQuerySpelling($queryText);
            $spellingParts = empty($spellingParts) ? $spellingParts = array('matched' => array($queryText)) : $spellingParts;

        } else {
            $spellingParts = array('matched' => array($queryText));
        }

        return $spellingParts;
    }


    /**
     * Dispatches query terms in two classes :
     * - matched terms   : Terms present into the indexes. Fuzzy search will not be applied on these terms
     * - unmatched terms : Terms missing into the indexes. Fuzzy search will be applied on these terms
     *
     * The spellchecker will be used to classify terms.
     *
     * @param string $queryText The analyzed query text
     *
     * @return array
     */
    protected function _analyzeQuerySpelling($queryText)
    {
        $result = array();

        $query = array(
            'index' => $this->getAdapter()->getCurrentIndex()->getCurrentName(),
            'type'  => $this->getType(),
            'size'  => 0,
        );

        $query['body']['suggest']['spelling'] = array(
            'text' => $queryText,
            'term' => array(
                'field'           => '_all',
                'min_word_length' => 2,
                'analyzer'        => 'whitespace'
            )
        );

        Varien_Profiler::start('ES:EXECUTE:SPELLING_QUERY');
        $response = $this->getClient()->search($query);
        Varien_Profiler::stop('ES:EXECUTE:SPELLING_QUERY');

        foreach ($response['suggest']['spelling'] as $token) {
            if (empty($token['options'])) {
                $result['matched'][] = Mage::helper('core/string')->substr($queryText, $token['offset'], $token['length']);
            } else {
                $this->_isSpellChecked = true;
                $result['unmatched'][] = Mage::helper('core/string')->substr($queryText, $token['offset'], $token['length']);
            }
        }

        return $result;
    }

    /**
     * Build the exact matches query part.
     *
     * @param string $queryText    Text to be searched.
     * @param array  $searchFields Search fields configuration.
     *
     * @return array
     */
    public function getExactMatchesQuery($queryText, $searchFields)
    {

        $query = array();
        $exactFields = array();

        foreach ($searchFields as $fieldName => $currentField) {
            $exactFields[] = sprintf("%s^%d", $fieldName, $currentField['weight']);
        }

        $query = array(
            'multi_match' => array(
                'query'                 => $queryText,
                'fields'                => $exactFields,
                'type'                  => 'cross_fields',
                'analyzer'              => 'analyzer' . '_' .$this->getLanguageCode(),
                'minimum_should_match'  => "2<100% 100<50%",
            )
        );

        return $query;
    }

    /**
     * Build a fuzzy search query for a search term
     *
     * @param string $queryText    Text to be searched.
     * @param array  $searchFields Search fields configuration.
     *
     * @return array
     */
    public function getFuzzyMatchesQuery($queryText, $searchFields)
    {

        $fuzzyQuery = array('dis_max' => array('tie_breaker' => 0));

        foreach ($searchFields as $fieldName => $currentField) {

            if ($currentField['fuzziness'] !== false) {

                $fuzzyQuery['dis_max']['queries'][] = array(
                    'match' => array(
                        $fieldName  => array(
                            'analyzer'      => 'analyzer' . '_' .$this->getLanguageCode(),
                            'query'         => $queryText,
                            'boost'         => $currentField['weight'],
                            'fuzziness'     => $currentField['fuzziness'],
                            'prefix_length' => $currentField['prefix_length'],
                        )
                    )
                );
            }
        }

        return $fuzzyQuery;
    }
}