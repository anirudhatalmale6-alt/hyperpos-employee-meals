@php
    $isEdit = $employee->exists;
    $action = $isEdit
        ? route('employee-meals.employees.update', $employee)
        : route('employee-meals.employees.store');
    $heading = $isEdit
        ? $employee->full_name
        : __('employee-meals::meals.employees.new');
@endphp

<x-admin-layout
    active="employee-meals-employees"
    :title="$heading"
    :crumbs="[
        ['label' => __('employee-meals::meals.employees.crumb_parent')],
        ['label' => __('employee-meals::meals.employees.title'), 'href' => route('employee-meals.employees.index')],
        ['label' => $heading],
    ]">

    <div class="page-wide">
        <form method="POST" action="{{ $action }}" enctype="multipart/form-data" novalidate>
            @csrf
            @if ($isEdit) @method('PATCH') @endif

            <div class="page-header mb-6">
                <div class="flex items-start gap-3">
                    <a href="{{ route('employee-meals.employees.index') }}"
                       class="pos-btn pos-btn-sm pos-btn-ghost"
                       aria-label="{{ __('employee-meals::meals.employees.title') }}">
                        <x-icon name="back" class="w-4 h-4" />
                    </a>
                    <div>
                        <h1 class="page-title">{{ $heading }}</h1>
                        <p class="page-sub">{{ __('employee-meals::meals.employees.sub') }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('employee-meals.employees.index') }}" class="pos-btn pos-btn-sm pos-btn-ghost">
                        {{ __('employee-meals::meals.employees.actions.discard') }}
                    </a>
                    <button type="submit" class="pos-btn pos-btn-sm pos-btn-primary">
                        <x-icon name="check" class="w-4 h-4" />
                        {{ $isEdit
                            ? __('employee-meals::meals.employees.actions.save')
                            : __('employee-meals::meals.employees.actions.create') }}
                    </button>
                </div>
            </div>

            {{-- Through <x-alert>, never a bare class="alert" and never
                 class="field-error": the first drops content into the grid's 20px
                 icon column, the second is display:none unconditionally. --}}
            @if ($errors->any())
                <x-alert type="danger" class="mb-4">
                    <strong>{{ __('employee-meals::meals.employees.errors_title') }}</strong>
                    @foreach ($errors->all() as $error)
                        <br>{{ $error }}
                    @endforeach
                </x-alert>
            @endif

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 items-start">
                <div class="space-y-5">

                    {{-- ── Identity ──────────────────────────────────── --}}
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">{{ __('employee-meals::meals.employees.cards.identity') }}</div>
                        </div>
                        <div class="card-body space-y-4">
                            <label class="field">
                                <span class="field-label">{{ __('employee-meals::meals.employees.fields.employee_no') }}</span>
                                <input type="text" name="employee_no" class="pos-input" required
                                       value="{{ old('employee_no', $employee->employee_no) }}"
                                       placeholder="CIP00125">
                            </label>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <label class="field">
                                    <span class="field-label">{{ __('employee-meals::meals.employees.fields.first_name') }}</span>
                                    <input type="text" name="first_name" class="pos-input" required
                                           value="{{ old('first_name', $employee->first_name) }}">
                                </label>
                                <label class="field">
                                    <span class="field-label">{{ __('employee-meals::meals.employees.fields.last_name') }}</span>
                                    <input type="text" name="last_name" class="pos-input" required
                                           value="{{ old('last_name', $employee->last_name) }}">
                                </label>
                            </div>

                            {{-- Photograph: show the face, not the file name. Either
                                 choose a file or take a still with the webcam on this
                                 PC; both end up in the same place. --}}
                            <div class="field" x-data="employeePhoto({{ \Illuminate\Support\Js::from([
                                'existing' => $employee->photo_path
                                    ? \Illuminate\Support\Facades\Storage::url($employee->photo_path)
                                    : null,
                            ]) }})">
                                <span class="field-label">{{ __('employee-meals::meals.employees.fields.photo') }}</span>

                                <div class="flex items-start gap-4">
                                    {{-- ⚠️ Sizes are inline, not Tailwind utilities.
                                         Tailwind here is PRECOMPILED and only ships the
                                         classes the vendor's own views use: w-28, h-28,
                                         w-20, h-20 and bg-surface are all absent, so a
                                         class-sized box silently renders at the image's
                                         natural size and blows the card apart. Checked
                                         against the built stylesheet. --}}
                                    <div class="rounded overflow-hidden flex items-center justify-center shrink-0"
                                         style="width:112px;height:112px;border:1px solid var(--border-subtle);background:var(--bg-surface)">
                                        <img :src="preview" x-show="preview" x-cloak alt=""
                                             style="width:100%;height:100%;object-fit:cover">
                                        <span x-show="!preview" class="field-help">
                                            {{ __('employee-meals::meals.employees.photo.none') }}
                                        </span>
                                    </div>

                                    <div class="space-y-2">
                                        <input type="file" name="photo" accept="image/*" class="pos-input"
                                               x-ref="file" @change="fromFile($event)">
                                        <input type="hidden" name="photo_capture" x-model="captured">

                                        <div class="flex items-center gap-2">
                                            <button type="button" class="pos-btn pos-btn-sm pos-btn-ghost"
                                                    x-show="!cameraOn" @click="startCamera()">
                                                <x-icon name="camera" class="w-4 h-4" />
                                                {{ __('employee-meals::meals.employees.photo.use_camera') }}
                                            </button>
                                            <button type="button" class="pos-btn pos-btn-sm pos-btn-primary"
                                                    x-show="cameraOn" x-cloak @click="capture()">
                                                {{ __('employee-meals::meals.employees.photo.take') }}
                                            </button>
                                            <button type="button" class="pos-btn pos-btn-sm pos-btn-ghost"
                                                    x-show="cameraOn" x-cloak @click="stopCamera()">
                                                {{ __('employee-meals::meals.employees.photo.cancel') }}
                                            </button>
                                            <button type="button" class="pos-btn pos-btn-sm pos-btn-ghost"
                                                    x-show="preview && !cameraOn" x-cloak @click="clearPhoto()">
                                                {{ __('employee-meals::meals.employees.photo.remove') }}
                                            </button>
                                        </div>

                                        <video x-ref="video" x-show="cameraOn" x-cloak autoplay playsinline muted
                                               class="rounded" style="width:220px;border:1px solid var(--border-subtle)"></video>
                                        <canvas x-ref="canvas" class="dt-hidden"></canvas>

                                        <span class="field-help" x-text="message"></span>
                                    </div>
                                </div>
                            </div>

                            <label class="field">
                                <span class="field-label">{{ __('employee-meals::meals.employees.fields.notes') }}</span>
                                <textarea name="notes" rows="3" class="pos-input">{{ old('notes', $employee->notes) }}</textarea>
                            </label>
                        </div>
                    </div>

                    {{-- ── Credentials ───────────────────────────────── --}}
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">{{ __('employee-meals::meals.employees.cards.credentials') }}</div>
                        </div>
                        <div class="card-body space-y-4">
                            <label class="field">
                                <span class="field-label">{{ __('employee-meals::meals.employees.fields.card_number') }}</span>
                                {{-- autocomplete off: a card reader types into this box like a
                                     keyboard, and a browser suggestion list would swallow it --}}
                                <input type="text" name="card_number" class="pos-input"
                                       autocomplete="off"
                                       value="{{ old('card_number', $employee->card_number) }}"
                                       placeholder="12345678">
                                <span class="field-help">{{ __('employee-meals::meals.employees.help.card_number') }}</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="space-y-5">

                    {{-- ── Company and placement ─────────────────────── --}}
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">{{ __('employee-meals::meals.employees.cards.placement') }}</div>
                        </div>
                        <div class="card-body space-y-4">
                            <label class="field">
                                <span class="field-label">{{ __('employee-meals::meals.employees.fields.company') }}</span>
                                <select name="em_company_id" class="pos-input" required>
                                    @foreach ($companies as $company)
                                        <option value="{{ $company->id }}"
                                            @selected((string) old('em_company_id', $employee->em_company_id) === (string) $company->id)>
                                            {{ $company->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </label>

                            <label class="field">
                                <span class="field-label">{{ __('employee-meals::meals.employees.fields.department') }}</span>
                                <select name="em_department_id" class="pos-input">
                                    <option value="">—</option>
                                    @foreach ($departments as $department)
                                        <option value="{{ $department->id }}"
                                            @selected((string) old('em_department_id', $employee->em_department_id) === (string) $department->id)>
                                            {{ $department->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </label>

                            <label class="field">
                                <span class="field-label">{{ __('employee-meals::meals.employees.fields.shift') }}</span>
                                <select name="em_shift_id" class="pos-input">
                                    <option value="">—</option>
                                    @foreach ($shifts as $shift)
                                        <option value="{{ $shift->id }}"
                                            @selected((string) old('em_shift_id', $employee->em_shift_id) === (string) $shift->id)>
                                            {{ $shift->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <span class="field-help">{{ __('employee-meals::meals.employees.help.shift') }}</span>
                            </label>
                        </div>
                    </div>

                    {{-- ── Meal entitlement ──────────────────────────── --}}
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">{{ __('employee-meals::meals.employees.cards.entitlement') }}</div>
                        </div>
                        <div class="card-body space-y-4">
                            <label class="field">
                                <span class="field-label">{{ __('employee-meals::meals.employees.fields.plan') }}</span>
                                <select name="em_subsidy_plan_id" class="pos-input">
                                    <option value="">—</option>
                                    @foreach ($plans as $plan)
                                        <option value="{{ $plan->id }}"
                                            @selected((string) old('em_subsidy_plan_id', $employee->em_subsidy_plan_id) === (string) $plan->id)>
                                            {{ $plan->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <span class="field-help">{{ __('employee-meals::meals.employees.help.plan') }}</span>
                            </label>

                            {{-- The link to Hyper's own customer record. Held by id and
                                 never written to, so nothing on the customer changes. --}}
                            <label class="field">
                                <span class="field-label">{{ __('employee-meals::meals.employees.fields.customer') }}</span>
                                <input type="number" name="customer_id" class="pos-input" min="1"
                                       value="{{ old('customer_id', $employee->customer_id) }}"
                                       placeholder="{{ __('employee-meals::meals.employees.status.not_linked') }}">
                                <span class="field-help">
                                    {{ __('employee-meals::meals.employees.help.customer') }}
                                    @if ($linkedCustomer)
                                        — {{ $linkedCustomer->name }}
                                    @endif
                                </span>
                            </label>

                            <label class="field field-toggle">
                                <input type="checkbox" name="meal_entitled" value="1"
                                       @checked(old('meal_entitled', $employee->meal_entitled))>
                                <span class="field-label">{{ __('employee-meals::meals.employees.fields.meal_entitled') }}</span>
                                <span class="field-help">{{ __('employee-meals::meals.employees.help.meal_entitled') }}</span>
                            </label>

                            <label class="field field-toggle">
                                <input type="checkbox" name="is_active" value="1"
                                       @checked(old('is_active', $employee->is_active))>
                                <span class="field-label">{{ __('employee-meals::meals.employees.fields.is_active') }}</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <script>
        function employeePhoto(config) {
            return {
                preview: config.existing,
                captured: '',
                cameraOn: false,
                message: '',
                stream: null,

                /** A chosen file - show it straight away, no upload needed. */
                fromFile(event) {
                    const file = event.target.files && event.target.files[0];
                    if (!file) { return; }
                    this.captured = '';               // a file wins over an old still
                    this.preview = URL.createObjectURL(file);
                    this.message = '';
                },

                async startCamera() {
                    // ⚠️ getUserMedia only exists on HTTPS (or localhost). On a plain
                    // http:// page the property is simply absent, which would otherwise
                    // read as "the button does nothing".
                    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                        this.message = @js(__('employee-meals::meals.employees.photo.needs_https'));
                        return;
                    }
                    try {
                        this.stream = await navigator.mediaDevices.getUserMedia({
                            video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } },
                            audio: false,
                        });
                    } catch (e) {
                        this.message = @js(__('employee-meals::meals.employees.photo.no_camera'));
                        return;
                    }
                    this.cameraOn = true;
                    this.message = '';
                    await this.$nextTick();
                    this.$refs.video.srcObject = this.stream;
                },

                capture() {
                    const video = this.$refs.video;
                    const canvas = this.$refs.canvas;

                    // ⚠️ Scale DOWN to a longest side of 640 before encoding. A modern
                    // webcam hands back 1080p or better, and a full-size JPEG as a
                    // base64 form field runs to hundreds of KB - big enough for a
                    // host's post_max_size or a mod_security rule to drop the whole
                    // POST, which shows up as "the photo just does not save". A face
                    // for identification needs nothing like that resolution.
                    const sw = video.videoWidth || 640;
                    const sh = video.videoHeight || 480;
                    const scale = Math.min(1, 640 / Math.max(sw, sh));
                    canvas.width = Math.round(sw * scale);
                    canvas.height = Math.round(sh * scale);
                    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);

                    this.captured = canvas.toDataURL('image/jpeg', 0.85);
                    this.preview = this.captured;

                    // A still and a chosen file would both be submitted; clear the
                    // file input so the still is unambiguously the one that counts.
                    if (this.$refs.file) { this.$refs.file.value = ''; }

                    this.stopCamera();

                    // Say the size out loud. If a photo ever fails to save, the first
                    // question is how big it was, and the operator can answer it
                    // without opening developer tools.
                    const kb = Math.round(this.captured.length / 1024);
                    this.message = @js(__('employee-meals::meals.employees.photo.taken'))
                        + ' (' + canvas.width + 'x' + canvas.height + ', ' + kb + ' KB)';
                },

                stopCamera() {
                    if (this.stream) {
                        this.stream.getTracks().forEach((t) => t.stop());
                        this.stream = null;
                    }
                    this.cameraOn = false;
                },

                clearPhoto() {
                    this.preview = null;
                    this.captured = '';
                    if (this.$refs.file) { this.$refs.file.value = ''; }
                    this.message = '';
                },

                destroy() { this.stopCamera(); },
            };
        }
    </script>
</x-admin-layout>
