{{--
    Card and fingerprint enrolment.

    The three things that can go wrong are reported SEPARATELY - libraries
    loaded, reader service answering, readers found - so the failure names
    itself instead of arriving as "nothing happened".
--}}
<x-admin-layout
    active="employee-meals-enrol"
    :title="__('employee-meals::meals.fingerprints.title')"
    :crumbs="[
        ['label' => __('employee-meals::meals.employees.crumb_parent')],
        ['label' => __('employee-meals::meals.fingerprints.title')],
    ]">

    <div class="page-wide"
         x-data="employeeMealsEnrolment({{ \Illuminate\Support\Js::from([
             'employeeId'  => $employee?->id,
             'state'       => $enrolled,
             'fingers'     => $fingers,
             'sdk'         => [
                 'websdk'  => route('employee-meals.sdk', 'websdk'),
                 'core'    => route('employee-meals.sdk', 'core'),
                 'devices' => route('employee-meals.sdk', 'devices'),
             ],
             'routes'      => [
                 'store' => $employee ? route('employee-meals.enrol.store', $employee) : null,
                 'card'  => $employee ? route('employee-meals.enrol.card', $employee) : null,
                 'clear' => $employee ? route('employee-meals.enrol.destroy', ['employee' => $employee->id, 'finger' => '__F__']) : null,
                 'test'  => route('employee-meals.enrol.test'),
             ],
             'matcherOk'   => $matcher['ok'],
             'text'        => [
                 'loaded'        => __('employee-meals::meals.fingerprints.reader.loaded'),
                 'notLoaded'     => __('employee-meals::meals.fingerprints.reader.not_loaded'),
                 'answering'     => __('employee-meals::meals.fingerprints.reader.answering'),
                 'notAnswering'  => __('employee-meals::meals.fingerprints.reader.not_answering'),
                 'none'          => __('employee-meals::meals.fingerprints.reader.none'),
                 'capturing'     => __('employee-meals::meals.fingerprints.fingers.capturing'),
                 'accepted'      => __('employee-meals::meals.fingerprints.test.accepted'),
                 'uncertain'     => __('employee-meals::meals.fingerprints.test.uncertain'),
                 'rejected'      => __('employee-meals::meals.fingerprints.test.rejected'),
             ],
         ]) }})">

        <div class="page-header mb-6">
            <div>
                <h1 class="page-title">{{ __('employee-meals::meals.fingerprints.title') }}</h1>
                <p class="page-sub">{{ __('employee-meals::meals.fingerprints.sub') }}</p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 items-start">
            <div class="space-y-5">

                {{-- ── Who ──────────────────────────────────────────── --}}
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">{{ __('employee-meals::meals.fingerprints.find') }}</div>
                    </div>
                    <div class="card-body space-y-4">
                        <form method="GET" action="{{ route('employee-meals.enrol.index') }}" class="flex items-end gap-2">
                            <label class="field flex-1">
                                <span class="field-label">{{ __('employee-meals::meals.fingerprints.find') }}</span>
                                <input type="search" name="q" value="{{ $q }}" class="pos-input"
                                       autocomplete="off"
                                       placeholder="{{ __('employee-meals::meals.fingerprints.find_placeholder') }}">
                            </label>
                            <button type="submit" class="pos-btn pos-btn-sm pos-btn-primary">
                                <x-icon name="search" class="w-4 h-4" />
                                {{ __('employee-meals::meals.fingerprints.search') }}
                            </button>
                        </form>

                        @if ($q !== '' && $matches->isEmpty())
                            <p class="field-help">{{ __('employee-meals::meals.fingerprints.no_matches') }}</p>
                        @endif

                        @if ($matches->isNotEmpty())
                            <table class="dt-table dt-table--lines">
                                <tbody>
                                    @foreach ($matches as $match)
                                        <tr>
                                            <td>{{ $match->employee_no }}</td>
                                            <td>{{ $match->full_name }}</td>
                                            <td>{{ $match->department?->name ?: '—' }}</td>
                                            <td class="dt-actions-col">
                                                <a class="pos-btn pos-btn-sm pos-btn-ghost"
                                                   href="{{ route('employee-meals.enrol.index', ['employee' => $match->id]) }}">
                                                    {{ __('employee-meals::meals.employees.actions.edit') }}
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif

                        @if ($employee)
                            <div class="flex items-center gap-4">
                                @if ($employee->photo_path)
                                    <img src="{{ \Illuminate\Support\Facades\Storage::url($employee->photo_path) }}"
                                         alt="" class="w-16 h-16 rounded object-cover">
                                @endif
                                <div>
                                    <div class="card-title">{{ $employee->full_name }}</div>
                                    <div class="field-help">
                                        {{ $employee->employee_no }}
                                        @if ($employee->department) · {{ $employee->department->name }} @endif
                                        @if ($employee->company) · {{ $employee->company->name }} @endif
                                    </div>
                                </div>
                            </div>
                        @else
                            <p class="field-help">{{ __('employee-meals::meals.fingerprints.choose') }}</p>
                        @endif
                    </div>
                </div>

                {{-- ── Access card ──────────────────────────────────── --}}
                @if ($employee)
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">{{ __('employee-meals::meals.fingerprints.card.title') }}</div>
                        </div>
                        <div class="card-body space-y-4">
                            <label class="field">
                                <span class="field-label">{{ __('employee-meals::meals.fingerprints.card.label') }}</span>
                                {{-- autocomplete off: the reader types into this box, and a
                                     browser suggestion list would swallow the keystrokes --}}
                                <input type="text" class="pos-input" autocomplete="off"
                                       x-model="cardNumber"
                                       @keydown.enter.prevent="saveCard()"
                                       value="{{ $employee->card_number }}">
                                <span class="field-help">{{ __('employee-meals::meals.fingerprints.card.help') }}</span>
                            </label>
                            <div class="flex items-center gap-3">
                                <button type="button" class="pos-btn pos-btn-sm pos-btn-primary" @click="saveCard()">
                                    <x-icon name="check" class="w-4 h-4" />
                                    {{ __('employee-meals::meals.fingerprints.card.save') }}
                                </button>
                                <span class="field-help" x-text="cardMessage"></span>
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            <div class="space-y-5">

                {{-- ── Reader status. Three separate lines on purpose. ── --}}
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">{{ __('employee-meals::meals.fingerprints.reader.title') }}</div>
                    </div>
                    <div class="card-body space-y-2">
                        <div class="flex items-center justify-between">
                            <span>{{ __('employee-meals::meals.fingerprints.reader.libraries') }}</span>
                            <span class="badge" :class="libsOk ? 'badge-positive' : 'badge-danger'"
                                  x-text="libsOk ? text.loaded : text.notLoaded"></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span>{{ __('employee-meals::meals.fingerprints.reader.agent') }}</span>
                            <span class="badge" :class="agentOk ? 'badge-positive' : 'badge-danger'"
                                  x-text="agentOk ? text.answering : text.notAnswering"></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span>{{ __('employee-meals::meals.fingerprints.reader.devices') }}</span>
                            <span class="badge" :class="devices.length ? 'badge-positive' : 'badge-warning'"
                                  x-text="devices.length ? devices.length : text.none"></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span>{{ __('employee-meals::meals.fingerprints.reader.matcher') }}</span>
                            <span class="badge {{ $matcher['ok'] ? 'badge-positive' : 'badge-warning' }}">
                                {{ $matcher['ok'] ? __('employee-meals::meals.fingerprints.reader.loaded') : __('employee-meals::meals.fingerprints.reader.none') }}
                            </span>
                        </div>

                        @unless ($matcher['ok'])
                            <p class="field-help">{{ $matcher['detail'] }}</p>
                        @endunless

                        {{-- Shown only once we know the agent never answered. The SDK's own
                             retry fires a "connection dropped" error a second later, and if
                             that were allowed to replace this the operator would be told
                             they lost something they never had.

                             x-show, not <template x-if>: the message is static, and x-show
                             keeps it in the DOM where a test can read it. --}}
                        {{-- Use the <x-alert> component, never a bare class="alert":
                             .alert is a three-column grid (20px icon · body · actions),
                             so content dropped straight into it lands in the 20px column
                             and wraps one word per line. And NOT class="field-error"
                             either - that one is display:none unconditionally and exists
                             only to turn a sibling input red. Both mistakes were made
                             here first. --}}
                        <x-alert type="warning" x-show="agentVerdict === 'missing'" x-cloak>
                            <strong>{{ __('employee-meals::meals.fingerprints.reader.install_title') }}</strong>
                            {{ __('employee-meals::meals.fingerprints.reader.install_body') }}
                            <br>{{ __('employee-meals::meals.fingerprints.reader.install_link') }}
                        </x-alert>
                    </div>
                </div>

                {{-- ── Fingers ──────────────────────────────────────── --}}
                @if ($employee)
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">{{ __('employee-meals::meals.fingerprints.fingers.title') }}</div>
                        </div>
                        <div class="card-body space-y-3">
                            @foreach ($fingers as $finger)
                                <div class="flex items-center justify-between gap-3">
                                    <div>
                                        <div>{{ __('employee-meals::meals.fingerprints.fingers.'.$finger) }}</div>
                                        <div class="field-help"
                                             x-text="state['{{ $finger }}'].samples + ' / 4'"></div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="badge"
                                              :class="state['{{ $finger }}'].enrolled ? 'badge-positive' : 'badge-warning'"
                                              x-text="state['{{ $finger }}'].enrolled
                                                  ? '{{ __('employee-meals::meals.fingerprints.fingers.enrolled') }}'
                                                  : '{{ __('employee-meals::meals.fingerprints.fingers.not_enrolled') }}'"></span>
                                        <button type="button" class="pos-btn pos-btn-sm pos-btn-primary"
                                                :disabled="busy"
                                                @click="capture('{{ $finger }}')">
                                            {{ __('employee-meals::meals.fingerprints.fingers.capture') }}
                                        </button>
                                        <button type="button" class="pos-btn pos-btn-sm pos-btn-ghost"
                                                :disabled="busy || !state['{{ $finger }}'].enrolled"
                                                @click="clearFinger('{{ $finger }}')">
                                            {{ __('employee-meals::meals.fingerprints.fingers.clear') }}
                                        </button>
                                    </div>
                                </div>
                            @endforeach

                            <p class="field-help">{{ __('employee-meals::meals.fingerprints.fingers.target_help') }}</p>
                            <p class="field-help" x-text="message"></p>
                        </div>
                    </div>
                @endif

                {{-- ── Test ─────────────────────────────────────────── --}}
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">{{ __('employee-meals::meals.fingerprints.test.title') }}</div>
                    </div>
                    <div class="card-body space-y-3">
                        <p class="field-help">{{ __('employee-meals::meals.fingerprints.test.help') }}</p>
                        <button type="button" class="pos-btn pos-btn-sm pos-btn-primary"
                                :disabled="busy" @click="runTest()">
                            {{ __('employee-meals::meals.fingerprints.test.start') }}
                        </button>

                        <div x-show="testResult" x-cloak>
                            <div class="card-title" x-text="testHeadline"></div>
                            <div class="field-help"
                                 x-text="testResult && testResult.employee
                                    ? testResult.employee.name + ' · ' + testResult.employee.employee_no
                                    : ''"></div>
                            <div class="field-help" x-text="testDetail"></div>
                            <div class="field-help">{{ __('employee-meals::meals.fingerprints.test.runner_help') }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function employeeMealsEnrolment(config) {
            return {
                ...config,
                libsOk: false,
                agentOk: false,
                // 'unknown' until we have actually decided. Guards against the SDK's
                // late CommunicationFailed overwriting the useful message.
                agentVerdict: 'unknown',
                devices: [],
                busy: false,
                message: '',
                cardNumber: '',
                cardMessage: '',
                testResult: null,

                init() {
                    this.cardNumber = document.querySelector('[x-model="cardNumber"]')?.value || '';
                    this.loadSdk();
                },

                async loadSdk() {
                    try {
                        // Order matters: WebSdk provides the channel the other two use.
                        await this.script(this.sdk.websdk);
                        await this.script(this.sdk.core);
                        await this.script(this.sdk.devices);
                    } catch (e) {
                        this.libsOk = false;
                        return;
                    }

                    // ⚠️ The UMD bundles publish under `dp`, NOT a global called
                    // Fingerprint - read from the shipped bundle, not from the
                    // documentation. websdk separately sets window.WebSdk, which is
                    // the channel the devices bundle talks over.
                    this.libsOk = typeof window.WebSdk !== 'undefined'
                        && !!(window.dp && window.dp.devices && window.dp.devices.FingerprintReader);

                    if (this.libsOk) { this.probeReader(); }
                },

                script(src) {
                    return new Promise((resolve, reject) => {
                        const el = document.createElement('script');
                        el.src = src;
                        el.onload = resolve;
                        el.onerror = () => reject(new Error('could not load ' + src));
                        document.head.appendChild(el);
                    });
                },

                reader() {
                    const ns = (window.dp && window.dp.devices) || {};
                    if (!ns.FingerprintReader) { return null; }
                    if (!this._api) { this._api = new ns.FingerprintReader(); }
                    return this._api;
                },

                async probeReader() {
                    const api = this.reader();
                    if (!api) { this.agentVerdict = 'missing'; return; }

                    try {
                        const list = await api.enumerateDevices();
                        this.agentOk = true;
                        this.agentVerdict = 'present';
                        this.devices = list || [];
                    } catch (e) {
                        // The agent is not there. Decide it ONCE - the SDK retries on its
                        // own and then reports a dropped connection, which would otherwise
                        // replace this with a message about losing something that never
                        // existed.
                        this.agentOk = false;
                        if (this.agentVerdict === 'unknown') { this.agentVerdict = 'missing'; }
                    }
                },

                async capture(finger) {
                    if (!this.routes.store) { return; }
                    const sample = await this.acquire();
                    if (!sample) { return; }

                    this.busy = true;
                    try {
                        const res = await this.post(this.routes.store, { finger, sample });
                        if (res.ok) {
                            this.state = res.state;
                            this.message = res.minutiae + ' points captured.';
                        } else {
                            this.message = res.message || 'That capture could not be stored.';
                        }
                    } finally {
                        this.busy = false;
                    }
                },

                async clearFinger(finger) {
                    this.busy = true;
                    try {
                        const url = this.routes.clear.replace('__F__', finger);
                        const res = await this.post(url, {}, 'DELETE');
                        if (res.ok) { this.state = res.state; this.message = ''; }
                    } finally {
                        this.busy = false;
                    }
                },

                async runTest() {
                    const sample = await this.acquire();
                    if (!sample) { return; }

                    this.busy = true;
                    try {
                        const res = await this.post(this.routes.test, { sample });
                        this.testResult = res.ok ? res : null;
                        if (!res.ok) { this.message = res.message || ''; }
                    } finally {
                        this.busy = false;
                    }
                },

                /** One touch from the reader, as a PNG. */
                acquire() {
                    const api = this.reader();
                    if (!api) { this.message = 'The reader service is not available.'; return null; }

                    this.message = this.text.capturing;

                    return new Promise((resolve) => {
                        let settled = false;

                        const done = (sample) => {
                            if (settled) { return; }
                            settled = true;
                            api.off('SamplesAcquired', onSamples);
                            api.off('ErrorOccurred', onError);
                            api.stopAcquisition().catch(() => {});
                            resolve(sample);
                        };

                        // ⚠️ `event.samples` is ALREADY an array - the bundle parses it
                        // before handing it over. Parsing it again throws and the touch
                        // is silently lost.
                        const onSamples = (event) => {
                            const samples = event && event.samples;
                            done(Array.isArray(samples) && samples.length ? samples[0] : null);
                        };
                        const onError = () => done(null);

                        api.on('SamplesAcquired', onSamples);
                        api.on('ErrorOccurred', onError);

                        // ⚠️ SampleFormat.PngImage is 5, not 4 - the enum skips 4
                        // (Raw 1, Intermediate 2, Compressed 3, PngImage 5). Asking for
                        // 4 gets nothing useful and fails much later as an undecodable
                        // image. Verified in the shipped bundle, not assumed.
                        const formats = (window.dp && window.dp.devices && window.dp.devices.SampleFormat) || {};
                        const PNG = typeof formats.PngImage === 'number' ? formats.PngImage : 5;

                        api.startAcquisition(PNG).catch(() => done(null));
                        setTimeout(() => done(null), 20000);
                    });
                },

                async saveCard() {
                    if (!this.routes.card) { return; }
                    const res = await this.post(this.routes.card, { card_number: this.cardNumber });
                    this.cardMessage = res.ok
                        ? (res.card_number ? 'Card saved.' : 'Card removed.')
                        : (res.message || 'That card could not be saved.');
                },

                async post(url, body, method = 'POST') {
                    const token = document.querySelector('meta[name="csrf-token"]')?.content;
                    const response = await fetch(url, {
                        method,
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': token || '',
                        },
                        body: JSON.stringify(body),
                    });
                    try {
                        return await response.json();
                    } catch (e) {
                        return { ok: false, message: 'The server returned an unreadable response.' };
                    }
                },

                get testHeadline() {
                    if (!this.testResult) { return ''; }
                    if (this.testResult.decision === 'accept') { return this.text.accepted; }
                    if (this.testResult.decision === 'uncertain') { return this.text.uncertain; }
                    return this.text.rejected;
                },

                get testDetail() {
                    if (!this.testResult) { return ''; }
                    return 'Score ' + this.testResult.score
                        + ', threshold ' + this.testResult.threshold
                        + ', runner-up ' + this.testResult.runner_up + '.';
                },
            };
        }
    </script>
</x-admin-layout>
