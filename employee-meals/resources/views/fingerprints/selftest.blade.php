{{--
    Reader self-test.

    Deliberately the plainest page that could possibly work: no Alpine, no admin
    layout, no build step, no framework of any kind - three script tags and a
    handful of lines of plain JavaScript.

    It exists to answer one question on the client's own PC. The same reader
    works on another site of ours, so if THIS page reads a finger, the fault is
    in how the enrolment screen drives the SDK. If this page fails too, the
    fault is between the browser and the reader service and the enrolment screen
    is a red herring.

    Everything the SDK does is written to the log, so the answer can be read off
    the screen and sent back without opening developer tools.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fingerprint reader self-test</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 24px; max-width: 820px; line-height: 1.5; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        p.sub { color: #666; margin: 0 0 20px; }
        button { font-size: 15px; padding: 10px 18px; margin-right: 8px; cursor: pointer; }
        #log { font-family: ui-monospace, monospace; font-size: 12.5px; background: #111; color: #d8d8d8;
               padding: 14px; border-radius: 8px; height: 340px; overflow: auto; white-space: pre-wrap; }
        .row { margin: 14px 0; }
        .ok { color: #1a7f37; font-weight: 600; }
        .bad { color: #b42318; font-weight: 600; }
    </style>
</head>
<body>
    <h1>Fingerprint reader self-test</h1>
    <p class="sub">
        Plain page, no framework. Press Start, then put a finger on the reader.
        Copy the whole log and send it back.
    </p>

    <div class="row">
        <button id="start">Start and read a finger</button>
        <button id="stop">Stop</button>
        <button id="copy">Copy the log</button>
    </div>

    <div id="log">ready.
</div>

    {{-- Plain script tags, in dependency order: WebSdk provides the channel,
         core provides the helpers, devices provides FingerprintReader. --}}
    <script src="{{ route('employee-meals.sdk', 'websdk') }}"></script>
    <script src="{{ route('employee-meals.sdk', 'core') }}"></script>
    <script src="{{ route('employee-meals.sdk', 'devices') }}"></script>

    <script>
        var logEl = document.getElementById('log');

        function log(line) {
            var stamp = new Date().toLocaleTimeString();
            logEl.textContent += stamp + '  ' + line + '\n';
            logEl.scrollTop = logEl.scrollHeight;
        }

        log('page origin: ' + window.location.origin);
        log('WebSdk global: ' + (typeof window.WebSdk));
        log('dp.core global: ' + (window.dp && window.dp.core ? 'yes' : 'NO'));
        log('dp.devices global: ' + (window.dp && window.dp.devices ? 'yes' : 'NO'));
        log('FingerprintReader: ' + (window.dp && window.dp.devices && window.dp.devices.FingerprintReader ? 'yes' : 'NO'));

        var reader = null;
        var deviceId = null;

        function makeReader() {
            if (reader) { return reader; }
            if (!(window.dp && window.dp.devices && window.dp.devices.FingerprintReader)) {
                log('cannot continue - the reader library did not load');
                return null;
            }
            reader = new window.dp.devices.FingerprintReader();

            // Both styles are wired on purpose. The SDK dispatches to an
            // `on<Event>` property AND to handlers registered with .on(), so if
            // one of them is not firing this page will show which.
            reader.on('DeviceConnected',      function () { log('event: DeviceConnected'); });
            reader.on('DeviceDisconnected',   function () { log('event: DeviceDisconnected'); });
            reader.on('AcquisitionStarted',   function () { log('event: AcquisitionStarted - place a finger now'); });
            reader.on('AcquisitionStopped',   function () { log('event: AcquisitionStopped'); });
            reader.on('QualityReported',      function (e) { log('event: QualityReported, code ' + (e && e.quality)); });
            reader.on('ErrorOccurred',        function (e) { log('event: ErrorOccurred ' + JSON.stringify(e && e.error)); });
            reader.on('CommunicationFailed',  function () { log('event: CommunicationFailed - the reader service stopped answering'); });
            reader.on('SamplesAcquired',      function (e) { onSample('via .on()', e); });
            reader.onSamplesAcquired = function (e) { onSample('via onSamplesAcquired property', e); };

            return reader;
        }

        function onSample(how, e) {
            var s = e && e.samples;
            if (typeof s === 'string') { try { s = JSON.parse(s); } catch (err) { s = null; } }
            if (Array.isArray(s) && s.length) {
                log('SAMPLE RECEIVED ' + how + ' - ' + String(s[0]).length + ' characters');
                log('>>> the reader works on this page <<<');
            } else {
                log('a sample arrived ' + how + ' but it was empty: ' + JSON.stringify(e && e.samples).slice(0, 120));
            }
        }

        document.getElementById('start').onclick = function () {
            var api = makeReader();
            if (!api) { return; }

            log('calling enumerateDevices...');
            api.enumerateDevices().then(function (list) {
                log('readers found: ' + JSON.stringify(list));
                deviceId = (list && list.length) ? list[0] : null;

                var formats = (window.dp.devices.SampleFormat) || {};
                var png = typeof formats.PngImage === 'number' ? formats.PngImage : 5;
                log('SampleFormat.PngImage = ' + png);

                // Named reader first; if that is refused, fall back to "any".
                log('startAcquisition with reader ' + (deviceId || '(none found)'));
                return api.startAcquisition(png, deviceId || undefined);
            }).then(function () {
                log('startAcquisition accepted - waiting for a finger');
            }).catch(function (err) {
                log('FAILED: ' + (err && (err.message || JSON.stringify(err))));
                if (deviceId) {
                    log('retrying without naming a reader...');
                    var formats = (window.dp.devices.SampleFormat) || {};
                    var png = typeof formats.PngImage === 'number' ? formats.PngImage : 5;
                    api.startAcquisition(png)
                        .then(function () { log('retry accepted - waiting for a finger'); })
                        .catch(function (e2) { log('retry also failed: ' + (e2 && (e2.message || JSON.stringify(e2)))); });
                }
            });
        };

        document.getElementById('stop').onclick = function () {
            if (!reader) { return; }
            reader.stopAcquisition(deviceId || undefined)
                .then(function () { log('stopped'); })
                .catch(function (e) { log('stop failed: ' + (e && e.message)); });
        };

        document.getElementById('copy').onclick = function () {
            navigator.clipboard.writeText(logEl.textContent)
                .then(function () { log('(log copied to the clipboard)'); })
                .catch(function () { log('(could not copy - select the text and copy it by hand)'); });
        };
    </script>
</body>
</html>
