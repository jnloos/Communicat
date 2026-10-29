<?php

// Schlüssel ist der kurze Klassenname der Pipeline. Ohne Eintrag wird kein Infotext gezeigt.
return [
    'RoundRobinPipeline' => 'Das Wort wandert nach dem letzten Sprecher in fester Reihenfolge von Sitz zu Sitz. Kein Modellaufruf entscheidet, wer spricht; nur der gewählte Experte denkt vor dem Sprechen. Das ist der Nullpunkt für den Anteil an Redebeiträgen.',
    'ScorePipeline' => 'Alle Experten denken zuerst und bieten eine Priorität von 1 bis 5 für den nächsten Beitrag. Das höchste Gebot spricht; bei Gleichstand entscheidet der Zufall (reproduzierbar über den Seed des Projekts). Die Auswahl selbst braucht keinen Modellaufruf.',
    'OrchestratorPipeline' => 'Ein Modell liest Verlauf und Langzeit-Zusammenfassung und wählt, wer als Nächstes spricht. Es sieht die Teilnehmerliste, aber keine privaten Gedanken, und gibt dem Sprecher nie Anweisungen. Nur der gewählte Experte denkt.',
    'QualityPipeline' => 'Alle Experten denken und schreiben einen Entwurf. Ein Judge-Modell sieht Teilnehmerliste und Entwürfe und wählt den Entwurf, der gerade am meisten beiträgt; dessen Autor spricht.',
    'BlindQualityPipeline' => 'Wie Quality, aber der Judge ist blind: keine Teilnehmerliste, keine Namen oder Tokens, die Entwürfe heißen A–D und werden jeden Turn neu gemischt. Trennt einen Titel, den der Judge sieht, von Status, der nur in den Entwürfen steckt.',
    'JevOrchestratorPipeline' => 'Wie Orchestrator, aber den nächsten Sprecher wählt Jev, ein festes Entscheidungsmodell von TypeSafe, statt des Teammodells. Jev sieht denselben Verlauf, dieselbe Langzeit-Zusammenfassung und Teilnehmerliste und nie einen privaten Gedanken. Es ist für jedes Teammodell dasselbe Modell.',
    'JevScorePipeline' => 'Wie Score, aber das Gebot von 1 bis 5 stammt von Jev, einem festen Entscheidungsmodell von TypeSafe, statt vom Agenten. Jeder Experte schreibt seinen Gedanken weiter mit dem Teammodell, das auch weiter nach seiner eigenen Priorität gefragt wird (nur protokolliert); Jev bewertet dann aus dessen eigener Sicht und mit dem eben geschriebenen Gedanken, wie dringend er das Wort will. Das höchste Gebot spricht. Unabhängig vom Teammodell ist nur das Gebot, nicht der Gedanke, den Jev liest.',
    'JevQualityPipeline' => 'Wie Quality, aber den Entwurf wählt Jev, ein festes Entscheidungsmodell von TypeSafe, statt des Teammodells. Jev sieht Teilnehmerliste und Entwürfe, für jedes Teammodell dieselben.',
    'JevBlindQualityPipeline' => 'Wie Blind Quality, aber den anonymen Entwurf wählt Jev, ein festes Entscheidungsmodell von TypeSafe, statt des Teammodells: keine Teilnehmerliste, keine Namen oder Tokens, die Entwürfe heißen A–D und werden jeden Turn neu gemischt.',
];
