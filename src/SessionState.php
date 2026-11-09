<?php
declare(strict_types=1);
namespace Semhoun\SlimSession;
enum SessionState: string
{
    case Absent = 'absent';
    case Open = 'open';
    case Closed = 'closed';
    case Destroyed = 'destroyed';
}
