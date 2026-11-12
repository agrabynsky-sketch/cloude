<?php
/**
 * Onboarding, mapping and booking sync from the command line.
 *
 *   php scripts/bakuun.php listing
 *   php scripts/bakuun.php link <bakuunHotelId> <utPropertyId>
 *   php scripts/bakuun.php refresh <bakuunHotelId>
 *   php scripts/bakuun.php automap <bakuunHotelId> [--save]
 *   php scripts/bakuun.php map <bakuunHotelId> room|rate_plan <bakuunCode> <utId>
 *   php scripts/bakuun.php unmap <bakuunHotelId> room|rate_plan <bakuunCode>
 *   php scripts/bakuun.php mappings <bakuunHotelId>
 *   php scripts/bakuun.php activate <bakuunHotelId> [--partial] [--no-push]
 *   php scripts/bakuun.php suspend <bakuunHotelId> [--no-push]
 *   php scripts/bakuun.php disconnect <bakuunHotelId> [--no-push] [--unit-travel]
 *   php scripts/bakuun.php sync                      (cron: pull, retry, acknowledge bookings)
 */
require_once dirname(__FILE__) . '/environment.php';

/**
 * @param  array                 $argv
 * @param  Zend_Application|null $application bootstrapped application (built from application.ini when null)
 * @param  resource|null         $out         output stream (STDOUT when null)
 * @param  resource|null         $err         error stream (STDERR when null)
 * @return int exit code
 */
function bakuun_cli(array $argv, $application = null, $out = null, $err = null)
{
    $out = $out ? $out : STDOUT;
    $err = $err ? $err : STDERR;
    $args = array();
    $flags = array();
    foreach (array_slice($argv, 1) as $arg) {
        if (strpos($arg, '--') === 0) {
            $flags[substr($arg, 2)] = true;
        } else {
            $args[] = $arg;
        }
    }
    $command = $args ? array_shift($args) : 'help';
    $required = array(
        'listing' => 0, 'link' => 2, 'refresh' => 1, 'automap' => 1, 'map' => 4, 'unmap' => 3, 'mappings' => 1,
        'activate' => 1, 'suspend' => 1, 'disconnect' => 1, 'sync' => 0,
    );
    if (!isset($required[$command]) || count($args) < $required[$command]) {
        $doc = file_get_contents(__FILE__);
        preg_match('#/\*\*(.*?)\*/#s', $doc, $m);
        fwrite($err, trim(preg_replace('/^\s*\* ?/m', '', $m[1])) . "\n");
        return 2;
    }

    if ($application === null) {
        $application = new Zend_Application(APPLICATION_ENV, APPLICATION_PATH . '/configs/application.ini');
        $application->bootstrap(array('db', 'bakuunOnboarding', 'bakuunBookingSync', 'bakuunMappings'));
    }
    $bootstrap = $application->getBootstrap();
    $onboarding = $bootstrap->getResource('bakuunOnboarding');
    try {
        switch ($command) {
            case 'listing':
                bakuun_table($out, $onboarding->listing(), array('bakuun_hotel_id', 'name', 'bakuun_status', 'ut_property_id', 'status'));
                break;
            case 'link':
                bakuun_property($out, $onboarding->link($args[0], $args[1]));
                fwrite($out, "Next: the hotel confirms the connection in Unit.Travel Extranet, then run automap.\n");
                break;
            case 'refresh':
                bakuun_property($out, $onboarding->refresh($args[0]));
                break;
            case 'automap':
                bakuun_table($out, $onboarding->autoMap($args[0], isset($flags['save'])), array('type', 'bakuun_code', 'bakuun_name', 'ut_id', 'ut_name', 'result'));
                if (!isset($flags['save'])) {
                    fwrite($out, "Suggestions only; run again with --save to store them.\n");
                }
                break;
            case 'map':
                $onboarding->map($args[0], $args[1], $args[2], $args[3]);
                fwrite($out, "Mapped {$args[1]} {$args[2]} to Unit.Travel id {$args[3]}.\n");
                break;
            case 'unmap':
                fwrite($out, $onboarding->unmap($args[0], $args[1], $args[2]) ? "Removed.\n" : "No such mapping.\n");
                break;
            case 'mappings':
                bakuun_table($out, $bootstrap->getResource('bakuunMappings')->all($args[0]), array('entity_type', 'bakuun_code', 'name', 'ut_id', 'ut_room_id', 'source'));
                $missing = $onboarding->unmapped($args[0]);
                if ($missing) {
                    fwrite($out, "\nNot mapped:\n");
                    bakuun_table($out, $missing, array('type', 'bakuun_code', 'name'));
                }
                break;
            case 'activate':
                bakuun_property($out, $onboarding->activate($args[0], isset($flags['partial']), !isset($flags['no-push'])));
                break;
            case 'suspend':
                bakuun_property($out, $onboarding->suspend($args[0], !isset($flags['no-push'])));
                break;
            case 'disconnect':
                bakuun_property($out, $onboarding->disconnect($args[0], !isset($flags['no-push']), isset($flags['unit-travel'])));
                break;
            case 'sync':
                foreach ($bootstrap->getResource('bakuunBookingSync')->run() as $result => $count) {
                    fwrite($out, "$result: $count\n");
                }
                break;
        }
    } catch (InvalidArgumentException $e) {
        fwrite($err, $e->getMessage() . "\n");
        return 1;
    } catch (Bakuun_Exception $e) {
        fwrite($err, "$e\n");
        return 1;
    } catch (UnitTravel_Exception $e) {
        fwrite($err, "Unit.Travel $e\n");
        return 1;
    }
    return 0;
}

function bakuun_property($out, array $property)
{
    foreach (array('bakuun_hotel_id', 'hotel_name', 'ut_property_id', 'ut_connection_status', 'status', 'source') as $field) {
        fwrite($out, str_pad($field, 22) . (isset($property[$field]) ? $property[$field] : '') . "\n");
    }
}

function bakuun_table($out, array $rows, array $columns)
{
    $widths = array();
    foreach ($columns as $column) {
        $widths[$column] = strlen($column);
        foreach ($rows as $row) {
            $widths[$column] = max($widths[$column], strlen((string) (isset($row[$column]) ? $row[$column] : '')));
        }
    }
    $line = array();
    foreach ($columns as $column) {
        $line[] = str_pad($column, $widths[$column]);
    }
    fwrite($out, rtrim(implode('  ', $line)) . "\n");
    foreach ($rows as $row) {
        $line = array();
        foreach ($columns as $column) {
            $line[] = str_pad((string) (isset($row[$column]) ? $row[$column] : ''), $widths[$column]);
        }
        fwrite($out, rtrim(implode('  ', $line)) . "\n");
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    exit(bakuun_cli($_SERVER['argv']));
}
