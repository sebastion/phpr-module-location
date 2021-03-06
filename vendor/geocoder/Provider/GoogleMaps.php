<?php

/**
 * This file is part of the Geocoder package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * @author William Durand <william.durand1@gmail.com>
 */
class Provider_GoogleMaps extends Provider_Abstract implements Provider_Interface
{
    const ENDPOINT_URL = 'http://maps.googleapis.com/maps/api/geocode/json?address=%s&sensor=false';

    /**
     * @var string
     */
    private $region = null;

    /**
     * @param \Geocoder\HttpAdapter\HttpAdapterInterface $adapter An HTTP adapter.
     * @param string                                     $locale  A locale (optional).
     * @param string                                     $region  Region biasing (optional).
     */
    public function __construct(HttpAdapter_Interface $adapter, $locale = null, $region = null)
    {
        parent::__construct($adapter, $locale);
        
        $this->region = $region;
    }

    /**
     * {@inheritDoc}
     */
    public function getGeocodedData($address)
    {
        if ('127.0.0.1' === $address) {
            return $this->getLocalhostDefaults();
        }

        // Google API returns invalid data if IP address given
        // This API doesn't handle IPs
        if (preg_match('/[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}/', $address)) {
            return $this->getDefaults();
        }

        $query = sprintf(self::ENDPOINT_URL, urlencode($address));

        return $this->executeQuery($query);
    }

    /**
     * {@inheritDoc}
     */
    public function getReversedData(array $coordinates)
    {
        return $this->getGeocodedData(sprintf('%F,%F', $coordinates[0], $coordinates[1]));
    }

    /**
     * {@inheritDoc}
     */
    public function getName()
    {
        return 'google_maps';
    }

    /**
     * @param  string $query
     * @return array
     */
    protected function executeQuery($query)
    {
        if (null !== $this->getLocale()) {
            $query = sprintf('%s&language=%s', $query, $this->getLocale());
        }

        if (null !== $this->getRegion()) {
            $query = sprintf('%s&region=%s', $query, $this->getRegion());
        }

        $content = $this->getAdapter()->getContent($query);

        if (null === $content) {
            return $this->getDefaults();
        }

        $json = json_decode($content);

        // API error
        if (!isset($json) || 'OK' !== $json->status) {
            return $this->getDefaults();
        }

        // no result
        if (!isset($json->results) || !count($json->results)) {
            return $this->getDefaults();
        }

        $result = $json->results[0];
        $resultset = $this->getDefaults();

        // update address components
        foreach ($result->address_components as $component) {
            foreach ($component->types as $type) {
                $this->updateAddressComponent($resultset, $type, $component);
            }
        }

        // update coordinates
        $coordinates = $result->geometry->location;
        $resultset['latitude']  = $coordinates->lat;
        $resultset['longitude'] = $coordinates->lng;

        $resultset['bounds'] = null;
        if (isset($result->geometry->bounds)) {
            $resultset['bounds'] = array(
                'south' => $result->geometry->bounds->southwest->lat,
                'west'  => $result->geometry->bounds->southwest->lng,
                'north' => $result->geometry->bounds->northeast->lat,
                'east'  => $result->geometry->bounds->northeast->lng
            );
        } elseif ('ROOFTOP' === $result->geometry->location_type) {
            // Fake bounds
            $resultset['bounds'] = array(
                'south' => $coordinates->lat,
                'west'  => $coordinates->lng,
                'north' => $coordinates->lat,
                'east'  => $coordinates->lng
            );
        }

        return $resultset;
    }

    /**
     * Update current resultset with given key/value.
     *
     * @param  array  $resultset resultset to update.
     * @param  String $type      component type.
     * @param  object $values    the component values;
     * @return array
     */
    protected function updateAddressComponent(&$resultset, $type, $values)
    {
        switch ($type) {
        case 'postal_code':
                $resultset['zipcode'] = $values->long_name;
                break;

            case 'locality':
                $resultset['city'] = $values->long_name;
                break;

            case 'administrative_area_level_2':
                $resultset['county'] = $values->long_name;
                break;

            case 'administrative_area_level_1':
                $resultset['region'] = $values->long_name;
                $resultset['regionCode'] = $values->short_name;
                break;

            case 'country':
                $resultset['country'] = $values->long_name;
                $resultset['countryCode'] = $values->short_name;
                break;

            case 'street_number':
                $resultset['streetNumber'] = $values->long_name;
                break;

            case 'route':
                $resultset['streetName'] = $values->long_name;
                break;

            case 'sublocality':
                $resultset['cityDistrict'] = $values->long_name;
                break;

            default:
        }

        return $resultset;
    }
    
    /**
     * Returns the configured region or null.
     *
     * @return string|null
     */
    protected function getRegion()
    {
        return $this->region;
    }
}
