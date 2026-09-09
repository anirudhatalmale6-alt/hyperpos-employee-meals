{{--
    Employee register.

    Deliberately server-rendered with a plain form filter rather than the core
    data-table's Alpine mixin: this screen ships inside a plugin, and every
    class used here is one that already exists in Hyper's compiled admin CSS.
    Tailwind only emits utilities it saw at build time, so a class invented
    here would silently do nothing.
--}}
<x-admin-layout
    active="employee-meals-employees"
    :title="__('employee-meals::meals.employees.title')"
    :crumbs="[
        ['label' => __('employee-meals::meals.employees.crumb_parent')],
        ['label' => __('employee-meals::meals.employees.title')],
    ]">

    <div class="page-wide">
        <div class="page-header mb-6">
            <div>
                <h1 class="page-title">{{ __('employee-meals::meals.employees.title') }}</h1>
                <p class="page-sub">{{ __('employee-meals::meals.employees.sub') }}</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('employee-meals.employees.create') }}" class="pos-btn pos-btn-sm pos-btn-primary">
                    <x-icon name="plus" class="w-4 h-4" />
                    {{ __('employee-meals::meals.employees.actions.add') }}
                </a>
            </div>
        </div>

        @if (session('status'))
            <div class="card mb-4">
                <div class="card-body">{{ session('status') }}</div>
            </div>
        @endif

        {{-- ── Filter bar. A plain GET form: bookmarkable, and it works with
             JavaScript switched off. ─────────────────────────────────── --}}
        <form method="GET" action="{{ route('employee-meals.employees.index') }}" class="inv-filter mb-3">
            <label class="field">
                <span class="field-label">{{ __('employee-meals::meals.employees.filter') }}</span>
                <div class="inv-search">
                    <input type="search"
                           name="q"
                           value="{{ $q }}"
                           class="pos-input"
                           placeholder="{{ __('employee-meals::meals.employees.search_placeholder') }}">
                </div>
            </label>

            <label class="field">
                <span class="field-label">{{ __('employee-meals::meals.employees.fields.company') }}</span>
                <select name="company" class="pos-input">
                    <option value="">{{ __('employee-meals::meals.employees.all_companies') }}</option>
                    @foreach ($companies as $company)
                        <option value="{{ $company->id }}" @selected((string) $companyId === (string) $company->id)>
                            {{ $company->name }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label class="field">
                <span class="field-label">{{ __('employee-meals::meals.employees.columns.status') }}</span>
                <select name="status" class="pos-input">
                    <option value="">{{ __('employee-meals::meals.employees.all_statuses') }}</option>
                    <option value="active" @selected($status === 'active')>
                        {{ __('employee-meals::meals.employees.only_active') }}
                    </option>
                    <option value="inactive" @selected($status === 'inactive')>
                        {{ __('employee-meals::meals.employees.only_inactive') }}
                    </option>
                </select>
            </label>

            <div class="flex items-end gap-2">
                <button type="submit" class="pos-btn pos-btn-sm pos-btn-primary">
                    <x-icon name="search" class="w-4 h-4" />
                    {{ __('employee-meals::meals.employees.filter') }}
                </button>
                <a href="{{ route('employee-meals.employees.index') }}" class="pos-btn pos-btn-sm pos-btn-ghost">
                    {{ __('employee-meals::meals.employees.clear') }}
                </a>
            </div>
        </form>

        @if ($employees->total() === 0)
            <div class="card">
                <div class="dt-empty">
                    <span class="dt-empty-icon"><x-icon name="customers" class="w-5 h-5" /></span>
                    <div class="dt-empty-title">
                        {{ $q !== '' || $status !== ''
                            ? __('employee-meals::meals.employees.no_results')
                            : __('employee-meals::meals.employees.empty') }}
                    </div>
                    <div class="dt-empty-sub">{{ __('employee-meals::meals.employees.empty_sub') }}</div>
                </div>
            </div>
        @else
            <div class="card">
                <table class="dt-table dt-table--lines">
                    <thead>
                        <tr>
                            <th></th>
                            <th>{{ __('employee-meals::meals.employees.columns.employee_no') }}</th>
                            <th>{{ __('employee-meals::meals.employees.columns.name') }}</th>
                            <th>{{ __('employee-meals::meals.employees.columns.department') }}</th>
                            <th>{{ __('employee-meals::meals.employees.columns.shift') }}</th>
                            <th>{{ __('employee-meals::meals.employees.columns.card') }}</th>
                            <th>{{ __('employee-meals::meals.employees.columns.plan') }}</th>
                            <th>{{ __('employee-meals::meals.employees.columns.status') }}</th>
                            <th class="dt-actions-col"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($employees as $employee)
                            <tr>
                                {{-- Face view, so the list can be checked against the
                                     person standing there. --}}
                                <td>
                                    @if ($employee->photo_path)
                                        <img src="{{ \Illuminate\Support\Facades\Storage::url($employee->photo_path) }}"
                                             alt="" class="rounded" style="width:40px;height:40px;object-fit:cover">
                                    @else
                                        <span class="rounded flex items-center justify-center"
                                              style="width:40px;height:40px;border:1px solid var(--border-subtle)">
                                            <x-icon name="user" class="w-4 h-4" />
                                        </span>
                                    @endif
                                </td>
                                <td>{{ $employee->employee_no }}</td>
                                <td>{{ $employee->full_name }}</td>
                                <td>{{ $employee->department?->name ?: '—' }}</td>
                                <td>{{ $employee->shift?->name ?: '—' }}</td>
                                <td>
                                    @if ($employee->card_number)
                                        {{ $employee->card_number }}
                                    @else
                                        <span class="badge badge-warning">
                                            {{ __('employee-meals::meals.employees.status.no_card') }}
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    @if ($employee->subsidyPlan)
                                        {{ $employee->subsidyPlan->name }}
                                    @else
                                        <span class="badge badge-warning">
                                            {{ __('employee-meals::meals.employees.status.no_plan') }}
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    @if ($employee->is_active)
                                        <span class="badge badge-positive">
                                            {{ __('employee-meals::meals.employees.status.active') }}
                                        </span>
                                    @else
                                        <span class="badge badge-danger">
                                            {{ __('employee-meals::meals.employees.status.inactive') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="dt-actions-col">
                                    <a href="{{ route('employee-meals.employees.edit', $employee) }}"
                                       class="pos-btn pos-btn-sm pos-btn-ghost">
                                        <x-icon name="edit" class="w-4 h-4" />
                                        {{ __('employee-meals::meals.employees.actions.edit') }}
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="dt-pager mt-3">
                {{ $employees->links() }}
            </div>
        @endif
    </div>
</x-admin-layout>
