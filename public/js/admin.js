function updateDonationGoalPreview() {
    var back = document.querySelector('.back');
    var front = document.querySelector('.front');
    var backTitle = document.getElementById('back-title');
    var frontTitle = document.getElementById('front-title');
    var goal = document.getElementById('goal');
    
    // Mise à jour des couleurs et du texte
    back.style.backgroundColor = document.getElementById('background_color').value;
    front.style.backgroundColor = document.getElementById('bar_color').value;

    back.style.color = document.getElementById('text_color_main').value;
    front.style.color = document.getElementById('text_color_alt').value;

    backTitle.textContent = document.getElementById('text_content').value;
    frontTitle.textContent = document.getElementById('text_content').value;
    goal.textContent = document.getElementById('goal').value;

    var currentDonation = goal.value / 2;

    // Mettez à jour le texte d'affichage des objectifs
    document.getElementById('back-goal-total').textContent = goal.value + ' €';
    document.getElementById('front-goal-total').textContent = goal.value + ' €';
    document.getElementById('back-goal-current').textContent = currentDonation + ' €';
    document.getElementById('front-goal-current').textContent = currentDonation + ' €';

    // Ajustement de la largeur de la barre si nécessaire
    front.style.width = (currentDonation / goal * 100) + '%'; // Ajustez selon la logique de votre application
}

// Ajoutez des écouteurs d'événements pour chaque champ d'entrée
document.getElementById('text_color_main').addEventListener('input', updateDonationGoalPreview);
document.getElementById('text_color_alt').addEventListener('input', updateDonationGoalPreview);
document.getElementById('text_content').addEventListener('input', updateDonationGoalPreview);
document.getElementById('bar_color').addEventListener('input', updateDonationGoalPreview);
document.getElementById('background_color').addEventListener('input', updateDonationGoalPreview);
document.getElementById('goal').addEventListener('input', updateDonationGoalPreview);

document.getElementById('previewBtn').addEventListener('click', function() {
    displayAlertBox('test de pseudo', 'test de message', '1000');
});
