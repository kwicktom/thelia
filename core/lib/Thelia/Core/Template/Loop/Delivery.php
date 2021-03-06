<?php
/*************************************************************************************/
/*                                                                                   */
/*      Thelia	                                                                     */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*      email : info@thelia.net                                                      */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      This program is free software; you can redistribute it and/or modify         */
/*      it under the terms of the GNU General Public License as published by         */
/*      the Free Software Foundation; either version 3 of the License                */
/*                                                                                   */
/*      This program is distributed in the hope that it will be useful,              */
/*      but WITHOUT ANY WARRANTY; without even the implied warranty of               */
/*      MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the                */
/*      GNU General Public License for more details.                                 */
/*                                                                                   */
/*      You should have received a copy of the GNU General Public License            */
/*	    along with this program. If not, see <http://www.gnu.org/licenses/>.         */
/*                                                                                   */
/*************************************************************************************/

namespace Thelia\Core\Template\Loop;
use Thelia\Core\Template\Element\LoopResult;
use Thelia\Core\Template\Element\LoopResultRow;
use Thelia\Core\Template\Loop\Argument\Argument;
use Thelia\Exception\OrderException;
use Thelia\Model\CountryQuery;
use Thelia\Module\BaseModule;
use Thelia\Module\DeliveryModuleInterface;

/**
 * Class Delivery
 * @package Thelia\Core\Template\Loop
 * @author Manuel Raynaud <mraynaud@openstudio.fr>
 * @author Etienne Roudeix <eroudeix@gmail.com>
 */
class Delivery extends BaseSpecificModule
{

    public function getArgDefinitions()
    {
        $collection = parent::getArgDefinitions();

        $collection->addArgument(
            Argument::createIntTypeArgument("country")
        );

        return $collection;
    }

    public function parseResults(LoopResult $loopResult)
    {
        $countryId = $this->getCountry();
        if (null !== $countryId) {
            $country = CountryQuery::create()->findPk($countryId);
            if (null === $country) {
                throw new \InvalidArgumentException('Cannot found country id: `' . $countryId . '` in delivery loop');
            }
        } else {
            $country = CountryQuery::create()->findOneByByDefault(1);
        }

        foreach ($loopResult->getResultDataCollection() as $deliveryModule) {
            $loopResultRow = new LoopResultRow($deliveryModule);

            $moduleInstance = $this->container->get(sprintf('module.%s', $deliveryModule->getCode()));

            if (false === $moduleInstance instanceof DeliveryModuleInterface) {
                throw new \RuntimeException(sprintf("delivery module %s is not a Thelia\Module\DeliveryModuleInterface", $deliveryModule->getCode()));
            }

            try {
                $postage = $moduleInstance->getPostage($country);
            } catch(OrderException $e) {
                switch($e->getCode()) {
                    case OrderException::DELIVERY_MODULE_UNAVAILABLE:
                        /* do not show this delivery module */
                        continue(2);
                        break;
                    default:
                        throw $e;
                }
            }

            $loopResultRow
                ->set('ID', $deliveryModule->getId())
                ->set('TITLE', $deliveryModule->getVirtualColumn('i18n_TITLE'))
                ->set('CHAPO', $deliveryModule->getVirtualColumn('i18n_CHAPO'))
                ->set('DESCRIPTION', $deliveryModule->getVirtualColumn('i18n_DESCRIPTION'))
                ->set('POSTSCRIPTUM', $deliveryModule->getVirtualColumn('i18n_POSTSCRIPTUM'))
                ->set('POSTAGE', $postage)
            ;

            $loopResult->addRow($loopResultRow);
        }

        return $loopResult;
    }

    protected function getModuleType()
    {
        return BaseModule::DELIVERY_MODULE_TYPE;
    }
}
