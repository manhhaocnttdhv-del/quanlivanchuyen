<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Tests\DatabaseTestCase;

class RoleNavigationTest extends DatabaseTestCase
{
    public function test_grouped_menu_keeps_authorized_destinations_without_empty_groups_or_duplicates(): void
    {
        foreach (['admin' => 19, 'operations' => 14, 'shipper' => 9, 'shop' => 10] as $role => $expectedCount) {
            $user = $role === 'shop' ? $this->shopOwner() : $this->person($role);
            $page = $this->actingAs($user)->get(route('dashboard'))->assertOk();
            $xpath = $this->navigation($page->getContent());
            $links = $xpath->query('//nav[@aria-label="Menu nghiệp vụ"]//a');
            $urls = [];
            foreach ($links as $link) {
                $urls[] = $link->getAttribute('href');
                $this->assertNotSame('', trim($link->textContent));
            }
            $this->assertCount($expectedCount, $urls, $role);
            $this->assertCount($expectedCount, array_unique($urls), $role);
            $this->assertSame(0, $xpath->query('//details[not(.//a)]')->length);
            $this->assertSame(1, $xpath->query('//*[@id="portal-nav"]//a[@aria-current="page"]')->length);
            foreach (['users.index', 'catalog.index', 'fees.index', 'audit.index'] as $name) {
                $this->assertSame($role === 'admin', in_array(route($name), $urls), $role.': '.$name);
            }
        }
    }

    public function test_current_destination_is_unique_and_its_group_opens_on_direct_navigation(): void
    {
        $this->actingAs($this->person());
        foreach (['transfers.index', 'stocktakes.index', 'finance.index', 'reports.home', 'users.index', 'shops.index', 'notifications.index', 'orders.scan'] as $name) {
            $page = $this->followingRedirects()->get(route($name))->assertOk();
            $xpath = $this->navigation($page->getContent());
            $current = $xpath->query('//*[@id="portal-nav"]//a[@aria-current="page"]');
            $this->assertSame(1, $current->length, $name);
            $this->assertSame(route($name), $current->item(0)->getAttribute('href'), $name);
            $this->assertSame(1, $xpath->query('//*[@id="portal-nav"]//a[@aria-current="page"]/ancestor::details[@open]')->length, $name);
        }
        $this->actingAs($this->shopOwner());
        $page = $this->get(route('orders.create'))->assertOk();
        $xpath = $this->navigation($page->getContent());
        $this->assertSame(route('orders.index'), $xpath->query('//*[@id="portal-nav"]//a[@aria-current="page"]')->item(0)->getAttribute('href'));
    }

    private function navigation(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($document);
    }
}
