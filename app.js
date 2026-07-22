document.addEventListener('DOMContentLoaded', () => {
    const toast = document.getElementById('toolToast');
    const showToast = (message, type = 'info') => {
        if (!toast) return;
        toast.textContent = message;
        toast.className = `toast visible ${type}`;
        window.clearTimeout(window.smartAgendaToast);
        window.smartAgendaToast = window.setTimeout(() => { toast.className = 'toast'; }, 4200);
    };

    window.confirmDelete = (form, label) => {
        const accepted = window.confirm(`Vas a eliminar ${label}. Esta acción no se puede deshacer. ¿Confirmas?`);
        if (accepted) {
            const confirmation = form.querySelector('[name="confirm_delete"]');
            if (confirmation) confirmation.value = '1';
        }
        return accepted;
    };

    document.querySelectorAll('[data-dismiss]').forEach((button) => {
        button.addEventListener('click', () => button.closest('.alert')?.remove());
    });

    const sidebar = document.getElementById('sidebar');
    document.getElementById('mobileMenu')?.addEventListener('click', () => sidebar?.classList.toggle('open'));
    document.querySelectorAll('.nav-link').forEach((link) => {
        link.addEventListener('click', () => {
            document.querySelectorAll('.nav-link').forEach((item) => item.classList.remove('active'));
            link.classList.add('active');
            sidebar?.classList.remove('open');
        });
    });

    document.addEventListener('keydown', (event) => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            document.querySelector('.search-form input')?.focus();
        }
    });

    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    document.getElementById('voiceSearch')?.addEventListener('click', () => {
        if (!SpeechRecognition) {
            showToast('Tu navegador no ofrece búsqueda por voz. Prueba Chrome o Edge.', 'warning');
            return;
        }
        const recognition = new SpeechRecognition();
        recognition.lang = 'es-CO';
        recognition.interimResults = false;
        recognition.onstart = () => showToast('Escuchando… di el evento, documento o contacto.', 'info');
        recognition.onerror = () => showToast('No pude reconocer la voz. Inténtalo de nuevo.', 'warning');
        recognition.onresult = (event) => {
            const text = event.results[0][0].transcript;
            const input = document.querySelector('.search-form input');
            if (input) {
                input.value = text;
                input.closest('form').submit();
            }
        };
        recognition.start();
    });

    document.getElementById('notificationButton')?.addEventListener('click', async () => {
        if (!('Notification' in window)) {
            showToast('Este navegador no admite notificaciones.', 'warning');
            return;
        }
        const permission = await Notification.requestPermission();
        if (permission === 'granted') {
            new Notification('SmartAgenda', { body: 'Las notificaciones del navegador están activadas.' });
            showToast('Recordatorios del navegador activados.', 'success');
        } else {
            showToast('No se concedió permiso para notificaciones.', 'warning');
        }
    });

    (window.smartAgenda?.reminders || []).forEach((reminder) => {
        const start = new Date(String(reminder.start).replace(' ', 'T')).getTime();
        const delay = start - (Number(reminder.minutes) * 60 * 1000) - Date.now();
        if (delay > 0 && delay < 2147483647) {
            window.setTimeout(() => {
                showToast(`Recordatorio: ${reminder.title}`, 'info');
                if ('Notification' in window && Notification.permission === 'granted') {
                    new Notification('SmartAgenda', { body: `Próximo: ${reminder.title}` });
                }
            }, delay);
        }
    });

    document.getElementById('gpsButton')?.addEventListener('click', () => {
        if (!navigator.geolocation) {
            showToast('Este dispositivo no ofrece geolocalización.', 'warning');
            return;
        }
        showToast('Solicitando permiso de ubicación…', 'info');
        navigator.geolocation.getCurrentPosition(
            (position) => {
                const { latitude, longitude } = position.coords;
                showToast(`Ubicación detectada: ${latitude.toFixed(4)}, ${longitude.toFixed(4)}`, 'success');
            },
            () => showToast('No se pudo obtener la ubicación. Revisa el permiso del navegador.', 'warning'),
            { enableHighAccuracy: true, timeout: 10000 }
        );
    });

    document.getElementById('bluetoothButton')?.addEventListener('click', async () => {
        if (!navigator.bluetooth) {
            showToast('Bluetooth web no está disponible en este navegador o contexto.', 'warning');
            return;
        }
        try {
            const device = await navigator.bluetooth.requestDevice({ acceptAllDevices: true });
            showToast(`Dispositivo detectado: ${device.name || 'sin nombre'}`, 'success');
        } catch (error) {
            if (error.name !== 'NotFoundError') showToast('No se pudo conectar con Bluetooth.', 'warning');
        }
    });

    document.getElementById('shareButton')?.addEventListener('click', async () => {
        const text = window.smartAgenda?.shareText || 'Mi agenda en SmartAgenda';
        if (navigator.share) {
            try { await navigator.share({ title: 'SmartAgenda', text }); } catch (error) { return; }
        } else {
            window.open(`https://wa.me/?text=${encodeURIComponent(text)}`, '_blank', 'noopener');
            showToast('Abriendo WhatsApp para compartir.', 'success');
        }
    });

    const cameraModal = document.getElementById('cameraModal');
    const cameraVideo = document.getElementById('cameraVideo');
    const cameraCanvas = document.getElementById('cameraCanvas');
    let cameraStream;
    const closeCamera = () => {
        cameraStream?.getTracks().forEach((track) => track.stop());
        cameraStream = null;
        if (cameraModal) cameraModal.hidden = true;
    };
    document.getElementById('cameraButton')?.addEventListener('click', async () => {
        if (!navigator.mediaDevices?.getUserMedia) {
            showToast('La cámara no está disponible en este navegador.', 'warning');
            return;
        }
        try {
            cameraStream = await navigator.mediaDevices.getUserMedia({ video: true });
            cameraVideo.srcObject = cameraStream;
            cameraModal.hidden = false;
        } catch (error) {
            showToast('La cámara requiere permiso y una conexión segura HTTPS o localhost.', 'warning');
        }
    });
    document.getElementById('closeCamera')?.addEventListener('click', closeCamera);
    document.getElementById('takePhoto')?.addEventListener('click', () => {
        if (!cameraVideo.videoWidth) return;
        cameraCanvas.width = cameraVideo.videoWidth;
        cameraCanvas.height = cameraVideo.videoHeight;
        cameraCanvas.getContext('2d').drawImage(cameraVideo, 0, 0);
        const snapshot = document.getElementById('cameraSnapshot');
        snapshot.src = cameraCanvas.toDataURL('image/jpeg', 0.85);
        snapshot.hidden = false;
        showToast('Captura tomada solo en este dispositivo.', 'success');
    });

    const documentModal = document.getElementById('documentModal');
    const documentFrame = document.getElementById('documentFrame');
    const documentImage = document.getElementById('documentImage');
    const documentText = document.getElementById('documentText');
    const documentUnsupported = document.getElementById('documentUnsupported');
    const documentTitle = document.getElementById('documentTitle');
    const documentDownloadLink = document.getElementById('downloadDocumentLink');
    const signedDownloadLink = document.getElementById('downloadSignedDocument');
    const documentSignatureSelect = document.getElementById('documentSignatureSelect');
    const documentSignatureCanvas = document.getElementById('documentSignatureCanvas');
    const documentSignaturePlaceholder = document.getElementById('documentSignaturePlaceholder');
    let activeDocument = null;
    let documentPreviewUrl = null;
    let signedObjectUrl = null;
    let documentHasInk = false;

    const hideDocumentPreviews = () => {
        if (documentFrame) documentFrame.hidden = true;
        if (documentImage) documentImage.hidden = true;
        if (documentText) documentText.hidden = true;
        if (documentUnsupported) documentUnsupported.hidden = true;
    };

    const openDocument = async (sourceUrl, name) => {
        if (!documentModal) return;
        documentModal.hidden = false;
        documentTitle.textContent = name || 'Documento';
        hideDocumentPreviews();
        documentDownloadLink.href = sourceUrl;
        const previewUrl = `${sourceUrl}${sourceUrl.includes('?') ? '&' : '?'}inline=1`;
        try {
            const response = await fetch(previewUrl, { credentials: 'same-origin' });
            if (!response.ok) throw new Error('No se pudo abrir el archivo.');
            const blob = await response.blob();
            const mime = (response.headers.get('content-type') || blob.type || '').split(';')[0].toLowerCase();
            activeDocument = { blob, mime, name: name || 'documento' };
            if (documentPreviewUrl) URL.revokeObjectURL(documentPreviewUrl);
            documentPreviewUrl = URL.createObjectURL(blob);
            if (mime === 'application/pdf') {
                documentFrame.src = documentPreviewUrl;
                documentFrame.hidden = false;
            } else if (mime.indexOf('image/') === 0) {
                documentImage.src = documentPreviewUrl;
                documentImage.hidden = false;
            } else if (mime === 'text/plain') {
                documentText.textContent = await blob.text();
                documentText.hidden = false;
            } else {
                documentUnsupported.hidden = false;
            }
            showToast('Archivo abierto dentro de SmartAgenda.', 'success');
        } catch (error) {
            activeDocument = null;
            documentUnsupported.hidden = false;
            showToast('No se pudo abrir el archivo. Puedes descargarlo para revisarlo.', 'warning');
        }
    };

    document.querySelectorAll('.file-row').forEach((row) => {
        const downloadLink = row.querySelector('a[href^="download.php"]');
        if (!downloadLink) return;
        const openButton = document.createElement('button');
        openButton.type = 'button';
        openButton.className = 'row-action open-document';
        openButton.textContent = 'Abrir';
        openButton.title = 'Abrir y firmar';
        openButton.addEventListener('click', () => openDocument(downloadLink.href, row.querySelector('.row-main strong')?.textContent.trim()));
        downloadLink.parentNode.insertBefore(openButton, downloadLink);
    });

    const closeDocument = () => {
        if (!documentModal) return;
        documentModal.hidden = true;
        documentFrame?.setAttribute('src', 'about:blank');
        if (documentPreviewUrl) URL.revokeObjectURL(documentPreviewUrl);
        documentPreviewUrl = null;
        activeDocument = null;
    };
    document.getElementById('closeDocument')?.addEventListener('click', closeDocument);

    const documentSignatureContext = documentSignatureCanvas?.getContext('2d');
    if (documentSignatureCanvas && documentSignatureContext) {
        documentSignatureContext.lineWidth = 2.5;
        documentSignatureContext.lineCap = 'round';
        documentSignatureContext.strokeStyle = '#172b4d';
        let drawingDocumentSignature = false;
        const documentPoint = (event) => {
            const point = event.touches?.[0] || event;
            const bounds = documentSignatureCanvas.getBoundingClientRect();
            return { x: (point.clientX - bounds.left) * (documentSignatureCanvas.width / bounds.width), y: (point.clientY - bounds.top) * (documentSignatureCanvas.height / bounds.height) };
        };
        documentSignatureCanvas.addEventListener('pointerdown', (event) => {
            event.preventDefault();
            drawingDocumentSignature = true;
            documentHasInk = true;
            documentSignaturePlaceholder?.classList.add('hidden');
            const point = documentPoint(event);
            documentSignatureContext.beginPath();
            documentSignatureContext.moveTo(point.x, point.y);
        });
        documentSignatureCanvas.addEventListener('pointermove', (event) => {
            if (!drawingDocumentSignature) return;
            event.preventDefault();
            const point = documentPoint(event);
            documentSignatureContext.lineTo(point.x, point.y);
            documentSignatureContext.stroke();
        });
        ['pointerup', 'pointerleave'].forEach((eventName) => documentSignatureCanvas.addEventListener(eventName, () => { drawingDocumentSignature = false; }));
        document.getElementById('clearDocumentSignature')?.addEventListener('click', () => {
            documentSignatureContext.clearRect(0, 0, documentSignatureCanvas.width, documentSignatureCanvas.height);
            documentHasInk = false;
            documentSignaturePlaceholder?.classList.remove('hidden');
        });
    }

    documentSignatureSelect?.addEventListener('change', () => {
        if (documentSignatureSelect.value) {
            documentSignaturePlaceholder?.classList.add('hidden');
            showToast('Firma guardada seleccionada.', 'info');
        } else if (!documentHasInk) {
            documentSignaturePlaceholder?.classList.remove('hidden');
        }
    });

    const signatureForDocument = () => {
        if (documentSignatureSelect?.value) return documentSignatureSelect.value;
        return documentHasInk ? documentSignatureCanvas.toDataURL('image/png') : '';
    };

    const saveSignedCopy = async (blob, fileName, mime) => {
        const formData = new FormData();
        formData.append('csrf', window.smartAgenda?.csrf || '');
        formData.append('action', 'save_signed_document');
        formData.append('nombre_documento', fileName);
        formData.append('archivo_firmado', blob, fileName);
        const response = await fetch('dashboard.php', { method: 'POST', body: formData, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.message || 'No se pudo guardar la copia.');
        if (signedObjectUrl) URL.revokeObjectURL(signedObjectUrl);
        signedObjectUrl = URL.createObjectURL(blob);
        signedDownloadLink.href = signedObjectUrl;
        signedDownloadLink.download = fileName;
        signedDownloadLink.hidden = false;
        showToast(result.message || 'Copia firmada guardada.', 'success');
    };

    document.getElementById('applyDocumentSignature')?.addEventListener('click', async () => {
        if (!activeDocument) { showToast('Abre primero un PDF o una imagen.', 'warning'); return; }
        if (activeDocument.mime !== 'application/pdf' && activeDocument.mime.indexOf('image/') !== 0) { showToast('La firma dentro de la aplicación funciona con PDF e imágenes.', 'warning'); return; }
        const signatureData = signatureForDocument();
        if (!signatureData) { showToast('Selecciona o dibuja una firma antes de continuar.', 'warning'); return; }
        const button = document.getElementById('applyDocumentSignature');
        button.disabled = true;
        button.textContent = 'Procesando…';
        try {
            const baseName = activeDocument.name.replace(/\.[^/.]+$/, '') || 'documento';
            if (activeDocument.mime === 'application/pdf') {
                if (!window.PDFLib) throw new Error('No se cargó el módulo PDF. Revisa tu conexión a internet.');
                const pdfDocument = await window.PDFLib.PDFDocument.load(await activeDocument.blob.arrayBuffer());
                const page = pdfDocument.getPages()[0];
                const embeddedSignature = await pdfDocument.embedPng(signatureData);
                const signatureWidth = Math.min(180, page.getWidth() * 0.3);
                const signatureScale = signatureWidth / embeddedSignature.width;
                page.drawImage(embeddedSignature, { x: page.getWidth() - (embeddedSignature.width * signatureScale) - 36, y: 36, width: embeddedSignature.width * signatureScale, height: embeddedSignature.height * signatureScale });
                const signedBytes = await pdfDocument.save();
                await saveSignedCopy(new Blob([signedBytes], { type: 'application/pdf' }), `${baseName}-firmado.pdf`, 'application/pdf');
            } else {
                const originalImage = new Image();
                originalImage.src = documentPreviewUrl;
                await new Promise((resolve, reject) => { originalImage.onload = resolve; originalImage.onerror = reject; });
                const imageCanvas = document.createElement('canvas');
                imageCanvas.width = originalImage.naturalWidth;
                imageCanvas.height = originalImage.naturalHeight;
                const imageContext = imageCanvas.getContext('2d');
                imageContext.drawImage(originalImage, 0, 0);
                const signatureImage = new Image();
                signatureImage.src = signatureData;
                await new Promise((resolve, reject) => { signatureImage.onload = resolve; signatureImage.onerror = reject; });
                const signatureWidth = Math.min(220, imageCanvas.width * 0.3);
                const signatureHeight = signatureImage.naturalHeight * (signatureWidth / signatureImage.naturalWidth);
                imageContext.drawImage(signatureImage, imageCanvas.width - signatureWidth - 36, imageCanvas.height - signatureHeight - 36, signatureWidth, signatureHeight);
                const imageBlob = await new Promise((resolve) => imageCanvas.toBlob(resolve, 'image/png'));
                await saveSignedCopy(imageBlob, `${baseName}-firmado.png`, 'image/png');
            }
        } catch (error) {
            showToast(error.message || 'No se pudo aplicar la firma.', 'warning');
        } finally {
            button.disabled = false;
            button.textContent = 'Firmar y guardar copia';
        }
    });

    const canvas = document.getElementById('signatureCanvas');
    const signatureForm = document.getElementById('signatureForm');
    const signaturePlaceholder = document.getElementById('signaturePlaceholder');
    let drawing = false;
    let hasInk = false;
    const context = canvas?.getContext('2d');
    if (canvas && context) {
        context.lineWidth = 2.5;
        context.lineCap = 'round';
        context.strokeStyle = '#172b4d';
        const coordinates = (event) => {
            const point = event.touches?.[0] || event;
            const bounds = canvas.getBoundingClientRect();
            return { x: (point.clientX - bounds.left) * (canvas.width / bounds.width), y: (point.clientY - bounds.top) * (canvas.height / bounds.height) };
        };
        const start = (event) => { event.preventDefault(); drawing = true; hasInk = true; signaturePlaceholder?.classList.add('hidden'); const point = coordinates(event); context.beginPath(); context.moveTo(point.x, point.y); };
        const move = (event) => { if (!drawing) return; event.preventDefault(); const point = coordinates(event); context.lineTo(point.x, point.y); context.stroke(); };
        const stop = () => { drawing = false; };
        canvas.addEventListener('pointerdown', start);
        canvas.addEventListener('pointermove', move);
        canvas.addEventListener('pointerup', stop);
        canvas.addEventListener('pointerleave', stop);
        document.getElementById('clearSignature')?.addEventListener('click', () => { context.clearRect(0, 0, canvas.width, canvas.height); hasInk = false; signaturePlaceholder?.classList.remove('hidden'); });
        signatureForm?.addEventListener('submit', (event) => {
            if (!hasInk) { event.preventDefault(); showToast('Dibuja tu firma antes de guardarla.', 'warning'); return; }
            document.getElementById('signatureData').value = canvas.toDataURL('image/png');
        });
    }

    const corrections = { 'reunion': 'reunión', 'administracion': 'administración', 'documentacion': 'documentación', 'tambien': 'también', 'informacion': 'información', 'programacion': 'programación', 'contraseña': 'contraseña' };
    document.querySelectorAll('input[type="text"], textarea').forEach((field) => {
        field.addEventListener('blur', () => {
            let value = field.value;
            Object.entries(corrections).forEach(([wrong, right]) => { value = value.replace(new RegExp(`\\b${wrong}\\b`, 'gi'), right); });
            if (value !== field.value) { field.value = value; showToast('Corregí algunos acentos comunes.', 'success'); }
        });
    });
});
