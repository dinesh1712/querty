<?php
namespace Drupal\highwire_alerts\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\node\Entity\Node;
use Drupal\Core\Datetime\DrupalDateTime;
use BetterDomDocument\DOMDoc;
use Drupal\Core\Render\Markup;
use DOMDocument;
/**
 * Service for printing or returning a message.
 */
class HighwireAlertsInfo
{
    /**
     * Prints a message.
     *
     * @return string
     *   The message to be printed.
     */
    public function highwire_alerts_criteria($node, $type)
{
    if ($type == "show_citation") {
        $heading = !$node->get("title")->isEmpty() ? $node->get("title")->getString() : "";
        $apath = $node->get("apath")->getValue()[0]["value"];
        $journal_title = !$node->get("journal_title")->isEmpty() ? htmlspecialchars($node->get("journal_title")->getString()) : "";
        $volume = !$node->get("volume")->isEmpty() ? $node->get("volume")->getString() : "";
        $issue_number = !$node->get("issue")->isEmpty() ? $node->get("issue")->getString() : "";
        $first_page = !$node->get("fpage")->isEmpty() ? $node->get("fpage")->getString() : "";
        $last_page = !$node->get("lpage")->isEmpty() ? $node->get("lpage")->getString() : "";
        $slug = !$node->get("slug")->isEmpty() ? $node->get("slug")->getString() : "";
        $doi = !$node->get("doi")->isEmpty() ? $node->get("doi")->getString() : "";

        // Determine publication date
        if (!empty($node->get("date_ppub")->getValue())) {
            $date_published = $node->get("date_ppub")->getValue()[0]["original"];
        } elseif (!empty($node->get("date_epub_version")->getValue())) {
            $date_published = $node->get("date_epub_version")->getValue()[0]["original"];
        } else {
            $date_published = null;
        }

        // Format issue list
        $issue_list = '';
        if (!empty($first_page) && !empty($last_page)) {
            $issue_list .= ($first_page == $last_page) ? $slug : $first_page . '-' . $last_page;
        } elseif (empty($last_page)) {
            $issue_list .= $slug;
        }

        // Format date
        $pub_date = '';
        if ($date_published) {
            $date_time = new \DateTime($date_published);
            $drupal_date_time = \Drupal\Core\Datetime\DrupalDateTime::createFromDateTime($date_time);
            $pub_date = $drupal_date_time->format("M Y,");
        }

        $journal_article_citation = $journal_title . " " . $pub_date . " " . $volume . "(" . $issue_number . "): " . $issue_list . "; <strong>DOI:</strong> " . $doi;

        // ✅ Author formatting logic
        $author_names = '';
        if ($node->hasField("authors_only_full_name") && !$node->get("authors_only_full_name")->isEmpty()) {
            $authors = $node->get("authors_only_full_name")->getValue();
            $count = count($authors);

            if ($count > 2) {
                $name_parts = explode(' ', trim($authors[0]['value']));
                $lastname = array_pop($name_parts);
                $firstname = implode(' ', $name_parts);
                $author_names = '<span class="label">' . $lastname . ', ' . $firstname . ' et al.</span>';
            }
            elseif ($count == 2) {
                $name_parts_1 = explode(' ', trim($authors[0]['value']));
                $lastname1 = array_pop($name_parts_1);
                $firstname1 = implode(' ', $name_parts_1);

                $name_parts_2 = explode(' ', trim($authors[1]['value']));
                $lastname2 = array_pop($name_parts_2);
                $firstname2 = implode(' ', $name_parts_2);

                $author_names = '<span class="label">' . $lastname1 . ', ' . $firstname1 . ' and ' . $lastname2 . ', ' . $firstname2 . '</span>';
            }
            elseif ($count == 1) {
                $name_parts = explode(' ', trim($authors[0]['value']));
                $lastname = array_pop($name_parts);
                $firstname = implode(' ', $name_parts);
                $author_names = '<span class="label">' . $lastname . ', ' . $firstname . '</span>';
            }
        } else {
            $author_names = '<span class="label">No Author</span>';
        }

        // Build the final HTML
        $url = "/content/" . $volume . "/" . $issue_number . "/" . $slug;
        $htmlmarkup = '<div class="current-issue panel-default"><div class="card-content"><h6>'
            . $heading
            . '</h6><div class="card-item-loop">'
            . $author_names
            . '</div><ul class="card-citation"><li><span>'
            . $journal_article_citation
            . "</span></li></ul></div></div>";

    } else {
        // Fallback for non-show_citation types
        if ($node->hasField("authors_only_full_name") && !$node->get("authors_only_full_name")->isEmpty()) {
            $authors = $node->get("authors_only_full_name")->getValue();
            if (!empty($authors[0]["value"])) {
                $parts = explode(' ', trim($authors[0]["value"]));
                $lastname = array_pop($parts);
                $firstname = implode(' ', $parts);
                $htmlmarkup = $lastname . ', ' . $firstname;
            } else {
                $htmlmarkup = '';
            }
        } else {
            $htmlmarkup = '';
        }
    }

    return $htmlmarkup;
}

    
    /**
     * Prepare a HTML string with alert information for articles.
     *
     * This method constructs an associative array with alert configurations
     * for different types of article notifications. Each alert type contains
     * information about what the alert monitors and how it should be displayed.
     *
     * @param string $type
     *   The type of alert configuration to be prepared. (Note: This parameter is not used in the current implementation)
     *
     * @return array
     *   An associative array containing alert information configurations.
     *   The array keys represent different alert types, and each key maps
     *   to an associative array with details about the alert.
     */
    public function highwire_alerts_article_highwire_alerts_info($type)
    {
        $alerts_info = [];
        $alerts_info["citedby"] = [
            "label" => t("Citations"),
            "type" => "article",
            "link text" => t("Alert me when this article is cited"),
            "criteria" => [
                ["name" => "querytype", "value" => "citedby"],
                ["name" => "resid", "value" => "[pisa-master]"],
            ],
        ];
        $alerts_info["correction"] = [
            "label" => t("Correction"),
            "type" => "article",
            "link text" => t("Alert me if a correction is posted"),
            "criteria" => [
                ["name" => "journal_querytype", "value" => "correction"],
                ["name" => "resid", "value" => "[pisa-master]"],
            ],
        ];
        $alerts_info["eletter"] = [
            "label" => t("eLetters"),
            "type" => "article",
            "link text" => t("Alert me when eletters are published"),
            "criteria" => [
                ["name" => "journal_querytype", "value" => "eletter"],
                ["name" => "resid", "value" => "[pisa-master]"],
            ],
        ];
        return $alerts_info;
    }

    /**
     * Fill in info for alert critieria
     * @param array $alert_info
     * @param object $entity
     * @param array $conf
     * @return array
     */
    public function highwire_alerts_criteria_tokens($alert_info, $entity)
    {
        foreach ($alert_info["criteria"] as $i => $criteria) {
            foreach ($criteria as $key => $value) {
                switch ($value) {
                    case "[pisa-master]":
                        $alert_info["criteria"][$i][$key] = !$entity
                            ->get("pisa_version")
                            ->isEmpty()
                            ? $entity->get("corpus")->getString() .
                                ";" .
                                $entity->get("pisa_version")->getString()
                            : "null";
                        break;
                    case "[drupal-id]":
                        //$alert_info['criteria'][$i][$key] = highwire_get_global_id('node', $entity);
                        break;
                    case "[hw_col_id]":
                        //$alert_info['criteria'][$i][$key] = $entity->field_highwire_collection_id[X][0]['value'];
                        break;
                    case "[jcode]":
                        $alert_info["criteria"][$i][$key] = !$entity
                            ->get("corpus")
                            ->isEmpty()
                            ? $entity->get("corpus")->getString()
                            : "null";
                        break;
                }
            }
        }
        return $alert_info["criteria"];
    }
    /**
     * Retrieve the first corpus code from site configuration.
     *
     * This method accesses the site configuration to obtain the corpus code
     * used for content lookups. It returns the first key from the site data
     * configuration array, which is expected to be the corpus code.
     *
     * @return string
     *   The corpus code retrieved from the site configuration.
     */
    public function get_corpus()
    {
        // Obtain the service responsible for content lookups.
        $lookup = \Drupal::service("highwire_content.lookup");

        // Retrieve the site data configuration from the "highwire_content" module.
        // This configuration contains various site-related settings, including corpus data.
        $site_data = \Drupal::config("highwire_content.site_data")->get(
            "highwire_content_site_data"
        );

        // Get the first key from the site data array, which is assumed to be the corpus code.
        $corpus = array_key_first($site_data);

        // Return the retrieved corpus code.
        return $corpus;
    }
    /**
     * Helps filtering alerts by journal.
     */
    public function filterByJournals($journals, $alerts = null)
    {
        $new_array = [];
        foreach ($alerts as $id => $alert) {
            foreach ($alert["alert_datasource"] as $data_source) {
                if (
                    $data_source["type_name"] == "journal_site" &&
                    in_array($data_source["name"], $journals)
                ) {
                    $new_array[$id] = $alert;
                }
            }
        }
        return $new_array;
    }

    /**
     * Helps filtering alerts by type.
     *
     * These type can one of these: correction, citedby, eletters, collection,
     * keyauth, ann, papetoc, cpetoc, etoc.
     */
    public function filterByType($type, $alerts)
    {
        $new_array = [];
        foreach ($alerts as $id => $alert) {
            if ($alert["alert_alert_type_name"] == $type) {
                $new_array[$id] = $alert;
            }
        }
        return $new_array;
    }
    /*
     **
     * Retrieve a Drupal node based on a corpus journal code.
     *
     * This method converts a given corpus journal code into a node ID
     * by looking it up through an external service, and then loads and
     * returns the corresponding node if it exists.
     *
     * @param string $corpus
     *   The corpus journal code used to generate the path for lookup. This should
     *   be a string representing the journal code, which will be appended with
     *   ".atom" and used to form the lookup path.
     *
     * @return \Drupal\node\Entity\Node|null
     *   The node object corresponding to the given corpus journal code if found,
     *   or null if no node exists for the given code.
     */
    public function highwire_nid_from_jcode($corpus)
    {
        // Obtain the lookup service for querying node IDs by path.
        $lookup = \Drupal::service("highwire_content.lookup");

        // Create the path for the lookup service by appending ".atom" to the corpus code.
        $corpus_apath = "/" . $corpus . ".atom";

        // Use the lookup service to find the node ID associated with the generated path.
        $journal_nid = $lookup->nidFromApath($corpus_apath);

        // Check if a node ID was returned from the lookup.
        if (!empty($journal_nid)) {
            // Load the node entity using the retrieved node ID.
            $node = Node::load($journal_nid);
        } else {
            // If no node ID was found, set the node to null.
            $node = null;
        }

        // Return the loaded node or null if no node was found.
        return $node;
    }
    /**
     * Retrieve alert information based on the specified type.
     *
     * This method constructs an associative array containing configuration
     * details for different types of article alerts. Each alert type is
     * included in the array if it matches the provided type.
     *
     * @param string $type
     *   The type of alert configuration to retrieve. Supported values are:
     *   - "citedby": Alerts when the article is cited.
     *   - "correction": Alerts if a correction is posted.
     *   - "eletter": Alerts when eLetters are published.
     *
     * @return array
     *   An associative array with alert information configurations. The
     *   array will contain configuration details for the specified alert type
     *   or an empty array if the type does not match any of the predefined types.
     */
    public function highwire_article_edit_info($type)
    {
        // Initialize an empty array to hold the alert information configurations
        $alerts_info = [];

        // Check the type and add the corresponding alert configuration
        if ($type == "citedby") {
            $alerts_info["citedby"] = [
                "label" => t("Citations"), // Human-readable label for the citation alert
                "type" => "article", // Type of the alert, which is related to articles
                "link text" => t("Alert me when this article is cited"), // Text for the alert link
                "criteria" => [
                    ["name" => "querytype", "value" => "citedby"], // Query type for citations
                    ["name" => "resid", "value" => "[pisa-master]"], // Resource identifier (placeholder value)
                ],
            ];
        } elseif ($type == "correction") {
            $alerts_info["correction"] = [
                "label" => t("Correction"), // Human-readable label for the correction alert
                "type" => "article", // Type of the alert, which is related to articles
                "link text" => t("Alert me if a correction is posted"), // Text for the alert link
                "criteria" => [
                    ["name" => "journal_querytype", "value" => "correction"], // Query type for corrections
                    ["name" => "resid", "value" => "[pisa-master]"], // Resource identifier (placeholder value)
                ],
            ];
        } elseif ($type == "eletter") {
            $alerts_info["eletter"] = [
                "label" => t("eLetters"), // Human-readable label for the eLetters alert
                "type" => "article", // Type of the alert, which is related to articles
                "link text" => t("Alert me when eletters are published"), // Text for the alert link
                "criteria" => [
                    ["name" => "journal_querytype", "value" => "eletter"], // Query type for eLetters
                    ["name" => "resid", "value" => "[pisa-master]"], // Resource identifier (placeholder value)
                ],
            ];
        }

        // Return the array containing the alert information configurations
        return $alerts_info;
    }
    /**
     * Returns the associative array for search criteria.
     */
    public function highwire_alerts_search_get_criteria_as_array($value)
    {
        // Decode all HTML/XML entities.
        $value = html_entity_decode($value);
        // Now have to convert XML to array.
        $querydoc = new DomDoc($value);
        $querydoc_array = $querydoc->getArray("inner");

        // Defining the default values for return.
        $default_values = [
            "author1" => "",
            "author2" => "",
            "title" => "",
            "title_flags" => "",
            "abstract_title" => "",
            "abstract_title_flags" => "",
            "text_abstract_title" => "",
            "text_abstract_title_flags" => "",
            "flag" => "",
        ];
        // Get the XML (array) part which we have all out data.
        $hwsearch_search = !empty(
            $querydoc_array["hwsearch:search"][0]["hwsearch:within-domain"][0][
                "hwsearch:and"
            ][0]
        )
            ? $querydoc_array["hwsearch:search"][0][
                "hwsearch:within-domain"
            ][0]["hwsearch:and"][0]
            : null;

        // Process if we have search criteria.
        if ($hwsearch_search) {
            // Get authors.
            if (!empty($hwsearch_search["hwsearch:author"])) {
                $default_values["author1"] =
                    $hwsearch_search["hwsearch:author"][0]["#text"];
            } elseif (
                !empty($hwsearch_search["hwsearch:and"][0]["hwsearch:author"])
            ) {
                foreach (
                    $hwsearch_search["hwsearch:and"][0]["hwsearch:author"]
                    as $value
                ) {
                    $authors[] = $value["#text"];
                }
                $author1 = $author2 = "";
                list($author1, $author2) = $authors;
                $default_values["author1"] = $author1;
                $default_values["author2"] = $author2;
            }
            // Flags.
            if (!empty($hwsearch_search["hwsearch:flag"][0]["#text"])) {
                $default_values["flag"] =
                    $hwsearch_search["hwsearch:flag"][0]["#text"];
            }
            // Get the texts.
            if (!empty($hwsearch_search["hwsearch:text"])) {
                foreach ($hwsearch_search["hwsearch:text"] as $value) {
                    switch ($value["@zones"]) {
                        case "title":
                            $default_values["title"] = $value["#text"];
                            $default_values["title_flags"] =
                                $value["@operator"];
                            break;

                        case "title abstract":
                            $default_values["abstract_title"] = $value["#text"];
                            $default_values["abstract_title_flags"] =
                                $value["@operator"];
                            break;

                        case "fulltext":
                            $default_values["text_abstract_title"] =
                                $value["#text"];
                            $default_values["text_abstract_title_flags"] =
                                $value["@operator"];
                            break;
                    }
                }
            }
        }
        return $default_values;
    }
    /**
     * Helper function to get sanitized default criteria array that is easy to work with
     */
    public function highwire_alerts_search_get_edit_criteria($alert)
    {
        $criteria = [];
        // As of now, search alerts are not fully converted to querydoc, so following
        // mapping will help serving the old criteria in new form.
        $mapping = [
            "AUTHOR1" => "author1",
            "AUTHOR2" => "author2",
            "TITLE" => "title",
            "ANDOREXACTTITLE" => "title_flags",
            "TITLEABSTRACT" => "abstract_title",
            "ANDOREXACTTITLEABS" => "abstract_title_flags",
            "FULLTEXT" => "text_abstract_title",
            "ANDOREXACTFULLTEXT" => "text_abstract_title_flags",
            "FLAG" => "flag",
        ];

        foreach ($alert["alert_criterion"] as $criterion) {
            if (
                array_key_exists("name", $criterion) &&
                array_key_exists("value", $criterion)
            ) {
                if ($criterion["name"] === "querydoc") {
                    $criteria = $this->highwire_alerts_search_get_criteria_as_array(
                        $criterion["value"]
                    );
                } else {
                    $key = $mapping[$criterion["name"]];
                    $value = $criterion["value"];
                    switch ($value) {
                        case "and":
                            $value = "match-all";
                            break;

                        case "or":
                            $value = "match-any";
                            break;

                        case "phrase":
                            $value = "match-phrase";
                            break;

                        default:
                            $value = $criterion["value"];
                            break;
                    }
                    $criteria[$key] = $value;
                }
            }
        }
        return $criteria;
    }
    /**
     * Helper function to build alert search criteria.
     */
    public function highwire_alerts_search_get_criteria_request_xml($values)
    {
        $dom_request = new DOMDocument("1.0", "UTF-8");
        $search_element = $dom_request->createElementNS(
            "http://schema.highwire.org/Search",
            "hwsearch:search"
        );
        $search_element->setAttribute(
            "xmlns:hwsearch",
            "http://schema.highwire.org/Search"
        );

        // Domain element.
        $domain_element = $dom_request->createElement("hwsearch:within-domain");
        $domain_element->setAttribute("domain", "highwire");

        $domain_element = $search_element->appendChild($domain_element);

        // <hwsearch:and>.
        $parent_and_element = $dom_request->createElement("hwsearch:and");
        $parent_and_element = $search_element->appendChild($parent_and_element);

        // Flag.
        $flag_element = $dom_request->createElement(
            "hwsearch:flag",
            $values["flag"]
        );
        $flag_element = $parent_and_element->appendChild($flag_element);

        // Authors.
        if (!empty($values["author1"]) && !empty($values["author2"])) {
            $author = $dom_request->createElement("hwsearch:and");
            $author->appendChild(
                $dom_request->createElement(
                    "hwsearch:author",
                    $values["author1"]
                )
            );
            $author->appendChild(
                $dom_request->createElement(
                    "hwsearch:author",
                    $values["author2"]
                )
            );
            $parent_and_element->appendChild($author);
        } elseif (!empty($values["author1"]) && empty($values["author2"])) {
            $author = $dom_request->createElement(
                "hwsearch:author",
                $values["author1"]
            );
            $parent_and_element->appendChild($author);
        } elseif (empty($values["author1"]) && !empty($values["author2"])) {
            $author = $dom_request->createElement(
                "hwsearch:author",
                $values["author2"]
            );
            $parent_and_element->appendChild($author);
        }

        // Only title.
        if (!empty($values["title"])) {
            $element = $dom_request->createElement(
                "hwsearch:text",
                $values["title"]
            );
            $element->setAttribute("operator", $values["title_flags"]);
            $element->setAttribute("zones", "title");
            $parent_and_element->appendChild($element);
        }

        // Only title and abstract.
        if (!empty($values["abstract_title"])) {
            $element = $dom_request->createElement(
                "hwsearch:text",
                $values["abstract_title"]
            );
            $element->setAttribute("operator", $values["abstract_title_flags"]);
            $element->setAttribute("zones", "title abstract");
            $parent_and_element->appendChild($element);
        }

        // Only text, title and abstract (full-text).
        if (!empty($values["text_abstract_title"])) {
            $element = $dom_request->createElement(
                "hwsearch:text",
                $values["text_abstract_title"]
            );
            $element->setAttribute(
                "operator",
                $values["text_abstract_title_flags"]
            );
            $element->setAttribute("zones", "fulltext");
            $parent_and_element->appendChild($element);
        }
        $domain_element->appendChild($parent_and_element);
        $dom_request->appendChild($search_element);
        return $dom_request->saveXML($dom_request->documentElement);
    }
}
