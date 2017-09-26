<?php

namespace app\helpers;

use DOMNode;

class Dom {

    /**
     * @param DOMNode $node
     * @return string
     */
    public function getInnerHtml($node) 
    {
        $document = $node->ownerDocument;
        $children = $node->childNodes;
        $innerHTML = '';
        foreach ($children as $child) {
            $innerHTML .= $document->saveHTML($child);
        }
        return $innerHTML;
    }

}
