import { warrantyForm } from './warranty-form.js';
import { notifications } from './notifications.js';
import { imageUploader } from './image-uploader.js';
import { datePicker } from './date-picker.js';
import { reports } from './reports.js';

const ASG = {
    config: {
        ajaxUrl: asgConfig.ajaxUrl,
        nonce: asgConfig.nonce,
        isRTL: asgConfig.isRTL,
        debugMode: asgConfig.debugMode
    },
    init() {
        this.warrantyForm.init();
        this.imageUploader.init();
        this.notifications.init();
        this.datePicker.init();
        this.reports.init();
    },
    warrantyForm,
    notifications,
    imageUploader,
    datePicker,
    reports
};

document.addEventListener('DOMContentLoaded', () => ASG.init());