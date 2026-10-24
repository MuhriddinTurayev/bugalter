@php
    $items = collect([
        ['label' => __('ui.settings.company'), 'href' => route('settings.company'), 'active' => request()->routeIs('settings.company*'), 'show' => true],
        ['label' => __('ui.settings.users'), 'href' => route('settings.users'), 'active' => request()->routeIs('settings.users*'), 'show' => auth()->user()->can('manage-users')],
        ['label' => __('ui.settings.employees'), 'href' => route('settings.employees'), 'active' => request()->routeIs('settings.employees*'), 'show' => auth()->user()->can('view-employees')],
        ['label' => __('ui.settings.ai'), 'href' => route('settings.ai'), 'active' => request()->routeIs('settings.ai*'), 'show' => auth()->user()->can('manage-users')],
        ['label' => __('ui.settings.audit'), 'href' => route('settings.audit'), 'active' => request()->routeIs('settings.audit'), 'show' => auth()->user()->can('manage-users')],
    ])->where('show', true)->values()->all();
@endphp

<x-ui.page-header :title="__('ui.settings.title')" :subtitle="__('ui.settings.subtitle')" />
<x-ui.subnav :items="$items" />
