<?php

namespace Artax;

interface ObservableClient extends Observable {
    
    const CANCEL = 'cancel';
    const REQUEST = 'request';
    const HEADERS = 'headers';
    const REDIRECT = 'redirect';
    const RESPONSE = 'response';
}
