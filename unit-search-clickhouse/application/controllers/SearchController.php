<?php

/**
 * Пример ZF1-контроллера поиска поверх ClickHouse (JSON API).
 *
 *   GET /search/hotels?checkin=2026-12-10&nights=7&guests=2&id_region=243836&stars=4,5&limit=30&offset=0
 *   GET /search/hotels?checkin=2026-12-10&checkout=2026-12-17&guests=2&id_hotel=1005,1006,1007
 *   GET /search/hotel?id_hotel=1005&checkin=2026-12-10&nights=7&guests=2
 *
 * Клиент ClickHouse настраивается один раз в Bootstrap (см. README, раздел "Подключение в приложении"):
 *   Search_ClickHouse_Client::setDefault(Search_ClickHouse_Client::factory($config->clickhouse));
 *
 * Названия отелей/номеров/тарифов, фото и т.п. здесь не отдаются: поисковый кэш возвращает только id и цены,
 * контент берите из своего кэша контента по id (30 отелей на страницу).
 */
class SearchController extends Zend_Controller_Action {
    public function init() {
        $this->_helper->viewRenderer->setNoRender(true);
    }

    public function hotelsAction() {
        $criteria = $this->_stayCriteria();
        foreach(array('channel', 'refundable', 'price_min', 'price_max', 'order', 'limit', 'offset') as $k) {
            $v = $this->_getParam($k);
            if(!is_null($v) && '' !== $v) {
                $criteria[$k] = $v;
            }
        }
        // списки через запятую: id_hotel=1005,1006&id_region=243836&stars=4,5&id_board_type=4,7
        foreach(array('id_hotel', 'id_region', 'id_country', 'id_city', 'stars', 'id_board_type') as $k) {
            $v = $this->_getParam($k);
            if(!empty($v)) {
                $criteria[$k] = array_map('intval', is_array($v) ? $v : explode(',', $v));
            }
        }
        $this->_respond(function() use ($criteria) {
            return Search_Model_Stay::getInstance()->search($criteria);
        });
    }

    public function hotelAction() {
        $hotelId = (int)$this->_getParam('id_hotel');
        $criteria = $this->_stayCriteria();
        $this->_respond(function() use ($hotelId, $criteria) {
            return array('id_hotel' => $hotelId, 'items' => Search_Model_Stay::getInstance()->hotelRates($hotelId, $criteria));
        });
    }

    protected function _stayCriteria() {
        $criteria = array(
            'checkin' => (string)$this->_getParam('checkin'),
            'guests'  => (int)$this->_getParam('guests', 2),
            'channel' => (int)$this->_getParam('channel', 1),
        );
        if($this->_getParam('checkout')) {
            $criteria['checkout'] = (string)$this->_getParam('checkout');
        } else {
            $criteria['nights'] = (int)$this->_getParam('nights', 1);
        }
        return $criteria;
    }

    protected function _respond($callback) {
        try {
            $t = microtime(true);
            $result = call_user_func($callback);
            $result['ms'] = round((microtime(true) - $t) * 1000, 1);
            $this->_helper->json($result);
        } catch(Exception $e) {
            // 400 — неверные параметры (сообщение можно показать); всё остальное — поиск недоступен,
            // текст ошибки ClickHouse/сети наружу не отдаём, а пишем в лог
            $isBadRequest = $e instanceof Search_ClickHouse_Exception && 400 == $e->getCode();
            if(!$isBadRequest) {
                error_log('search error: ' . $e->getMessage());
            }
            $this->getResponse()->setHttpResponseCode($isBadRequest ? 400 : 503);
            $this->_helper->json(array('error' => $isBadRequest ? $e->getMessage() : 'search unavailable'));
        }
    }
}
