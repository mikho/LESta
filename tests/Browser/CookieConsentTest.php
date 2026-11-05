<?php

test('declining the cookie banner stores no preference cookie', function () {
    $page = visit(route('home'));

    $page->assertSee('Cookies and local storage')
        ->click('Decline')
        ->assertDontSee('Cookies and local storage')
        ->assertNoJavaScriptErrors();

    expect($page->script('document.cookie'))->not->toContain('appearance=')->not->toContain('sidebar_state=');
});

test('accepting the cookie banner allows the theme preference to be saved', function () {
    $page = visit(route('home'));

    $page->click('Accept')
        ->assertDontSee('Cookies and local storage')
        ->assertNoJavaScriptErrors();

    expect($page->script('document.cookie'))->toContain('cookie_consent=granted');
});
