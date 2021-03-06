<?php
/**
 * Valitor Module for Magento 2.x.
 *
 * Copyright © 2020 Valitor. All rights reserved.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SDM\Valitor\Model\Config\Source;

use Valitor\Response\TerminalsResponse;
use Magento\Framework\Option\ArrayInterface;
use SDM\Valitor\Model\SystemConfig;

class Terminals implements ArrayInterface
{

    /**
     * @var SystemConfig
     */
    private $systemConfig;

    public function __construct(SystemConfig $systemConfig)
    {
        $this->systemConfig = $systemConfig;
    }

    /**
     * Return array of options as value-label pairs
     *
     * @return array Format: array(array('value' => '<value>', 'label' => '<label>'), ...)
     */
    public function toOptionArray()
    {
        $terminals = [];
        try {
            $call = new \Valitor\Api\Others\Terminals($this->systemConfig->getAuth());
            /** @var TerminalsResponse $response */
            $response    = $call->call();
            $terminals[] = ['value' => ' ', 'label' => '-- Please Select --'];
            foreach ($response->Terminals as $terminal) {
                $terminals[] = ['value' => $terminal->Title, 'label' => $terminal->Title];
            }
        } catch (\Exception $e) {
        }
        // Sort the terminals alphabetically
        array_multisort(array_column($terminals, 'label'), SORT_ASC, SORT_NUMERIC, $terminals);

        return $terminals;
    }
}
