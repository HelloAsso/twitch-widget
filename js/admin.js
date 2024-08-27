function updateDonationGoalPreview() {
    // Récupérer les éléments de la barre de donation
    var goalBar = document.getElementById('goal-bar');
    var totalBar = document.getElementById('total-bar');
    var goalCurrent = document.getElementById('goal-current');
    var goalTotal = document.getElementById('goal-total');
    var title = document.getElementById('title');

    // Mettre à jour les couleurs des éléments
    goalBar.style.backgroundColor = document.getElementById('background_color').value;
    goalBar.style.borderColor = document.getElementById('text_color').value;
    totalBar.style.backgroundColor = document.getElementById('bar_color').value;

    // Mettre à jour les couleurs de texte
    goalCurrent.style.color = document.getElementById('text_color').value;
    goalTotal.style.color = document.getElementById('text_color').value;
    title.style.color = document.getElementById('text_color').value;
    title.textContent = document.getElementById('text_content').value;
}

document.getElementById('text_color').addEventListener('input', updateDonationGoalPreview);
document.getElementById('text_content').addEventListener('input', updateDonationGoalPreview);
document.getElementById('bar_color').addEventListener('input', updateDonationGoalPreview);
document.getElementById('background_color').addEventListener('input', updateDonationGoalPreview);

document.getElementById('previewBtn').addEventListener('click', function() {
    displayAlertBox('test de pseudo', 'test de message', '1000');
});
