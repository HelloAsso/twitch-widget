import { displayAlertBox } from './alert.js';

function initGoalsManager() {
    const manager = document.getElementById('goalsManager');
    if (!manager) return;

    const chipsEl = manager.querySelector('.goals-chips');
    const addInput = manager.querySelector('.goals-add-input');
    const addBtn = manager.querySelector('.goals-add-btn');
    const hiddenContainer = manager.querySelector('.goals-hidden');

    function getGoals() {
        return Array.from(hiddenContainer.querySelectorAll('input[name="goal_amounts[]"]'))
            .map((el) => parseInt(el.value, 10))
            .filter((v) => !isNaN(v) && v > 0);
    }

    function syncHidden(goals) {
        hiddenContainer.innerHTML = '';
        goals.forEach((g) => {
            const inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = 'goal_amounts[]';
            inp.value = g;
            hiddenContainer.appendChild(inp);
        });
    }

    function renderChips(goals) {
        chipsEl.innerHTML = '';
        goals.forEach((g) => {
            const chip = document.createElement('span');
            chip.className = 'badge bg-primary rounded-pill d-flex align-items-center gap-2 px-3 py-2';
            chip.style.fontSize = '0.95rem';
            chip.innerHTML = `${g} €<button type="button" class="btn-close btn-close-white" aria-label="Supprimer" style="font-size:0.6rem;"></button>`;
            chip.querySelector('.btn-close').addEventListener('click', () => {
                const updated = getGoals().filter((v) => v !== g);
                syncHidden(updated);
                renderChips(updated);
                window.previewGoal = updated[0] || 1000;
            });
            chipsEl.appendChild(chip);
        });
    }

    function addGoal() {
        const val = parseInt(addInput.value, 10);
        if (!val || val <= 0) return;
        const goals = getGoals();
        if (!goals.includes(val)) {
            goals.push(val);
            goals.sort((a, b) => a - b);
            syncHidden(goals);
            renderChips(goals);
            window.previewGoal = goals[0] || 1000;
        }
        addInput.value = '';
    }

    addBtn.addEventListener('click', addGoal);
    addInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            addGoal();
        }
    });

    const initial = getGoals();
    initial.sort((a, b) => a - b);
    syncHidden(initial);
    renderChips(initial);
    window.previewGoal = initial[0] || 1000;
}

initGoalsManager();

/**
 * Lie une liste d'inputs à une fonction de mise à jour de preview.
 * Exécute la fonction immédiatement, puis sur chaque événement 'input'.
 */
function bindPreviewInputs(inputIds, updateFn) {
    updateFn();
    inputIds.forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', updateFn);
    });
}

function updateDonationGoalPreview() {
    const back = document.querySelector('.back');
    const front = document.querySelector('.front');
    const backTitle = document.getElementById('back-title');
    const frontTitle = document.getElementById('front-title');

    back.style.backgroundColor = document.getElementById('background_color').value;
    front.style.backgroundColor = document.getElementById('bar_color').value;

    back.style.color = document.getElementById('text_color_main').value;
    front.style.color = document.getElementById('text_color_alt').value;

    const textContent = document.getElementById('text_content').value;
    backTitle.textContent = textContent;
    frontTitle.textContent = textContent;

    const goalValue = window.previewGoal || 1000;
    const currentDonation = goalValue / 2;

    document.getElementById('back-goal-total').textContent = `${goalValue} €`;
    document.getElementById('front-goal-total').textContent = `${goalValue} €`;
    document.getElementById('back-goal-current').textContent = `${currentDonation} €`;
    document.getElementById('front-goal-current').textContent = `${currentDonation} €`;

    front.style.width = `${(currentDonation / goalValue) * 100}%`;
}

const donationBarForm = document.getElementById('donationBarForm');
if (donationBarForm) {
    bindPreviewInputs(
        ['text_color_main', 'text_color_alt', 'text_content', 'bar_color', 'background_color'],
        updateDonationGoalPreview
    );
}

const alertBoxForm = document.getElementById('alertBoxForm');
if (alertBoxForm) {
    document.getElementById('previewBtn').addEventListener('click', () => {
        displayAlertBox('test de pseudo', 'test de message', '1000');
    });
}

// ── Card widget live preview ──────────────────────────────────
const cardWidgetForm = document.getElementById('cardWidgetForm');
if (cardWidgetForm) {
    function updateCardPreview() {
        const preview = document.getElementById('cardPreview');
        const tag = document.getElementById('cardPreviewTag');
        const title = document.getElementById('cardPreviewTitle');
        const desc = document.getElementById('cardPreviewDesc');
        const amount = document.getElementById('cardPreviewAmount');
        const barFill = document.getElementById('cardPreviewBarFill');
        const goalEl = document.getElementById('cardPreviewGoal');
        const pct = document.getElementById('cardPreviewPct');

        const bgColor = document.getElementById('card_background_color').value;
        const textColor = document.getElementById('card_text_color').value;
        const barColor = document.getElementById('card_bar_color').value;
        const barBgColor = document.getElementById('card_bar_background_color').value;
        const tagColor = document.getElementById('card_tag_color').value;
        const tagBgColor = document.getElementById('card_tag_background_color').value;
        const goalValue = window.previewGoal || 1000;

        if (preview) {
            preview.style.backgroundColor = bgColor;
            preview.style.color = textColor;
        }
        if (tag) {
            tag.style.color = tagColor;
            tag.style.backgroundColor = tagBgColor;
            tag.textContent = `✏️ ${document.getElementById('card_tag').value || ''}`;
        }
        if (title) title.textContent = document.getElementById('card_title').value || '';
        if (desc) desc.textContent = document.getElementById('card_description').value || '';

        const halfGoal = goalValue / 2;
        if (amount) amount.textContent = `${halfGoal} €`;
        if (barFill) {
            barFill.style.backgroundColor = barColor;
            barFill.style.width = '50%';
            barFill.parentElement.style.backgroundColor = barBgColor;
        }
        if (goalEl) goalEl.innerHTML = `Objectif : <strong>${goalValue} €</strong>`;
        if (pct) pct.textContent = '50%';

        const cta = document.getElementById('cardPreviewCta');
        if (cta) {
            cta.style.color = tagColor;
            cta.style.backgroundColor = tagBgColor;
        }
    }

    bindPreviewInputs(
        [
            'card_tag', 'card_title', 'card_description',
            'card_background_color', 'card_text_color', 'card_bar_color',
            'card_bar_background_color', 'card_tag_color', 'card_tag_background_color',
        ],
        updateCardPreview
    );

    // Live image preview
    const cardImageInput = document.getElementById('card_image');
    if (cardImageInput) {
        cardImageInput.addEventListener('change', function () {
            const file = this.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = (e) => {
                const imgEl = document.getElementById('cardPreviewImage');
                if (imgEl) imgEl.style.backgroundImage = `url(${e.target.result})`;
            };
            reader.readAsDataURL(file);
        });
    }
}
