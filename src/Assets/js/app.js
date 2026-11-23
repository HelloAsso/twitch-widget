import '../scss/styles.scss';
import * as bootstrap from 'bootstrap'

// Les scripts inline des vues (création de stream, toasts…) utilisent l'objet global `bootstrap`
window.bootstrap = bootstrap;


const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl))


const toastLiveExample = document.getElementById('liveToast')
if (toastLiveExample) {
    const toastBootstrap = bootstrap.Toast.getOrCreateInstance(toastLiveExample)
    toastBootstrap.show()
}

function confirmDelete(event) {
    if (!confirm("Êtes-vous sûr de vouloir supprimer cet élément ? Cette action est irréversible.")) {
        event.preventDefault();
    }
}
