<?php

// Previous three-source pipeline, bound to the real statistics service.
return function (array $filters): array {
    $messages = [];
    $messages = array_merge($messages, $this->loadReportMessages(base_path('RedmineMantencion/data/reportes')));
    $messages = array_merge($messages, $this->loadLiveMessages(''));
    $messages = array_merge($messages, $this->loadExtraMessages(''));

    $filtered = $this->filterMessages($messages, $filters);

    $stats = $this->computeStats($filtered);
    $stats['filtros_aplicados'] = $filters;

    return $stats;
};
