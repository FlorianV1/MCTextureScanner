<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Texture Scanner</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-4">
<div class="bg-white rounded-lg shadow-lg p-8 max-w-md w-full">
    <h1 class="text-3xl font-bold text-gray-800 mb-6">Texture Scanner</h1>

    @if ($errors->any())
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded mb-4">
            <ul class="list-disc list-inside">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form action="{{ route('scan') }}" method="POST" enctype="multipart/form-data" id="scanForm">
        @csrf

        <div class="mb-6">
            <label class="block text-gray-700 font-semibold mb-2">
                Textures Folder <span class="text-red-500">*</span>
            </label>
            <input
                type="file"
                name="textures[]"
                id="texturesInput"
                webkitdirectory
                multiple
                required
                class="block w-full text-sm text-gray-500
                        file:mr-4 file:py-2 file:px-4
                        file:rounded file:border-0
                        file:text-sm file:font-semibold
                        file:bg-blue-50 file:text-blue-700
                        hover:file:bg-blue-100"
            >
            <p class="text-sm text-gray-500 mt-1">Select the textures folder containing .png files</p>
            <p id="fileCount" class="text-sm text-blue-600 mt-1 hidden"></p>
        </div>

        <div class="mb-6">
            <label class="block text-gray-700 font-semibold mb-2">
                settings.py <span class="text-red-500">*</span>
            </label>
            <input
                type="file"
                name="settings_py"
                accept=".py"
                required
                class="block w-full text-sm text-gray-500
                        file:mr-4 file:py-2 file:px-4
                        file:rounded file:border-0
                        file:text-sm file:font-semibold
                        file:bg-blue-50 file:text-blue-700
                        hover:file:bg-blue-100"
            >
            <p class="text-sm text-gray-500 mt-1">Upload the settings.py file with item definitions</p>
        </div>

        <button
            type="submit"
            id="submitBtn"
            class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded transition duration-200"
        >
            Scan
        </button>
    </form>
</div>

<!-- Progress Overlay -->
<div id="progressOverlay" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-2xl p-8 max-w-md w-full mx-4">
        <div class="text-center mb-6">
            <div class="inline-block animate-spin rounded-full h-16 w-16 border-b-4 border-blue-600 mb-4"></div>
            <h2 class="text-2xl font-bold text-gray-800" id="progressTitle">Uploading Files...</h2>
            <p class="text-gray-600 mt-2" id="progressSubtitle">Please wait while we process your textures</p>
        </div>

        <!-- Upload Progress Bar -->
        <div class="mb-6">
            <div class="flex justify-between text-sm text-gray-600 mb-2">
                <span>Upload Progress</span>
                <span id="uploadPercent">0%</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
                <div id="uploadProgress" class="bg-blue-600 h-3 rounded-full transition-all duration-300" style="width: 0%"></div>
            </div>
        </div>

        <!-- Processing Steps -->
        <div class="space-y-3">
            <div class="flex items-center gap-3" id="step1">
                <div class="w-6 h-6 rounded-full border-2 border-gray-300 flex items-center justify-center">
                    <div class="w-3 h-3 rounded-full bg-gray-300"></div>
                </div>
                <span class="text-gray-600">Uploading files</span>
            </div>
            <div class="flex items-center gap-3" id="step2">
                <div class="w-6 h-6 rounded-full border-2 border-gray-300 flex items-center justify-center">
                    <div class="w-3 h-3 rounded-full bg-gray-300"></div>
                </div>
                <span class="text-gray-600">Parsing settings.py</span>
            </div>
            <div class="flex items-center gap-3" id="step3">
                <div class="w-6 h-6 rounded-full border-2 border-gray-300 flex items-center justify-center">
                    <div class="w-3 h-3 rounded-full bg-gray-300"></div>
                </div>
                <span class="text-gray-600">Scanning textures</span>
            </div>
            <div class="flex items-center gap-3" id="step4">
                <div class="w-6 h-6 rounded-full border-2 border-gray-300 flex items-center justify-center">
                    <div class="w-3 h-3 rounded-full bg-gray-300"></div>
                </div>
                <span class="text-gray-600">Generating report</span>
            </div>
        </div>

        <p class="text-xs text-gray-500 mt-6 text-center">
            Do not close this window
        </p>
    </div>
</div>

<script>
    const form = document.getElementById('scanForm');
    const overlay = document.getElementById('progressOverlay');
    const uploadProgress = document.getElementById('uploadProgress');
    const uploadPercent = document.getElementById('uploadPercent');
    const progressTitle = document.getElementById('progressTitle');
    const progressSubtitle = document.getElementById('progressSubtitle');
    const texturesInput = document.getElementById('texturesInput');
    const fileCount = document.getElementById('fileCount');
    const submitBtn = document.getElementById('submitBtn');

    // Show file count when folder selected
    texturesInput.addEventListener('change', function() {
        const files = this.files;
        const pngCount = Array.from(files).filter(f => f.name.endsWith('.png')).length;
        if (pngCount > 0) {
            fileCount.textContent = `${pngCount} PNG file${pngCount !== 1 ? 's' : ''} selected`;
            fileCount.classList.remove('hidden');
        }
    });

    // Mark step as active
    function activateStep(stepNum) {
        const step = document.getElementById(`step${stepNum}`);
        const circle = step.querySelector('div');
        const dot = step.querySelector('div > div');
        const text = step.querySelector('span');

        circle.classList.remove('border-gray-300');
        circle.classList.add('border-blue-600');
        dot.classList.remove('bg-gray-300');
        dot.classList.add('bg-blue-600');
        text.classList.remove('text-gray-600');
        text.classList.add('text-blue-600', 'font-semibold');
    }

    // Mark step as complete
    function completeStep(stepNum) {
        const step = document.getElementById(`step${stepNum}`);
        const circle = step.querySelector('div');
        const text = step.querySelector('span');

        circle.classList.remove('border-blue-600');
        circle.classList.add('border-green-600', 'bg-green-600');
        circle.innerHTML = '<svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>';
        text.classList.remove('text-blue-600');
        text.classList.add('text-green-600');
    }

    form.addEventListener('submit', function(e) {
        e.preventDefault();

        // Show overlay
        overlay.classList.remove('hidden');
        submitBtn.disabled = true;

        let currentStep = 1;
        activateStep(1);

        // Actually submit the form with XMLHttpRequest
        const formData = new FormData(form);
        const xhr = new XMLHttpRequest();

        xhr.upload.addEventListener('progress', function(e) {
            if (e.lengthComputable) {
                const realProgress = (e.loaded / e.total) * 100;
                uploadProgress.style.width = realProgress + '%';
                uploadPercent.textContent = Math.round(realProgress) + '%';

                // Update steps based on upload progress
                if (realProgress > 25 && currentStep === 1) {
                    completeStep(1);
                    currentStep = 2;
                    activateStep(2);
                    progressTitle.textContent = 'Parsing Settings...';
                }
                if (realProgress > 50 && currentStep === 2) {
                    completeStep(2);
                    currentStep = 3;
                    activateStep(3);
                    progressTitle.textContent = 'Scanning Textures...';
                    progressSubtitle.textContent = 'Checking dimensions and matching files';
                }
                if (realProgress > 75 && currentStep === 3) {
                    completeStep(3);
                    currentStep = 4;
                    activateStep(4);
                    progressTitle.textContent = 'Generating Report...';
                    progressSubtitle.textContent = 'Almost there!';
                }
            }
        });

        xhr.addEventListener('load', function() {
            console.log('XHR Status:', xhr.status);
            console.log('XHR Response:', xhr.responseText);

            if (xhr.status >= 200 && xhr.status < 400) {
                try {
                    // Try to parse JSON response
                    const response = JSON.parse(xhr.responseText);

                    if (response.success && response.redirect) {
                        // Complete all steps
                        completeStep(1);
                        completeStep(2);
                        completeStep(3);
                        completeStep(4);

                        uploadProgress.style.width = '100%';
                        uploadPercent.textContent = '100%';
                        progressTitle.textContent = 'Scan Complete!';
                        progressSubtitle.textContent = 'Redirecting...';

                        // Redirect after brief delay
                        setTimeout(function() {
                            window.location.href = response.redirect;
                        }, 500);
                    } else {
                        throw new Error('Invalid response format');
                    }
                } catch (error) {
                    console.error('Parse error:', error);
                    // If it's not JSON, it might be a redirect HTML
                    // Just submit the form normally
                    form.submit();
                }
            } else {
                console.error('HTTP Error:', xhr.status);
                progressTitle.textContent = 'Error Occurred';
                progressSubtitle.textContent = 'HTTP Error ' + xhr.status + '. Please try again.';
                submitBtn.disabled = false;
                setTimeout(() => overlay.classList.add('hidden'), 3000);
            }
        });

        xhr.addEventListener('error', function(e) {
            console.error('XHR Error:', e);
            progressTitle.textContent = 'Upload Failed';
            progressSubtitle.textContent = 'Please check your connection and try again';
            submitBtn.disabled = false;
            setTimeout(() => overlay.classList.add('hidden'), 3000);
        });

        xhr.open('POST', form.action);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.send(formData);
    });
</script>
</body>
</html>
