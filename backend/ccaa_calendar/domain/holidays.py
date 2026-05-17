from dataclasses import dataclass
from datetime import date, timedelta


@dataclass(frozen=True)
class ChileHoliday:
    date: date
    label: str
    kind: str = "national"
    source: str = "castel-base"

    @property
    def is_irrenunciable(self) -> bool:
        return self.kind == "irrenunciable"


def calculate_easter_sunday(year: int) -> date:
    """Meeus/Jones/Butcher Gregorian algorithm, inherited from the Castel calendar."""
    a = year % 19
    b = year // 100
    c = year % 100
    d = b // 4
    e = b % 4
    f = (b + 8) // 25
    g = (b - f + 1) // 3
    h = (19 * a + b - d - g + 15) % 30
    i = c // 4
    k = c % 4
    correction = (32 + 2 * e + 2 * i - h - k) % 7
    m = (a + 11 * h + 22 * correction) // 451
    month = (h + correction - 7 * m + 114) // 31
    day = ((h + correction - 7 * m + 114) % 31) + 1
    return date(year, month, day)


def chile_holidays(year: int) -> list[ChileHoliday]:
    easter = calculate_easter_sunday(year)
    return [
        ChileHoliday(date(year, 1, 1), "Anio Nuevo", "irrenunciable"),
        ChileHoliday(easter - timedelta(days=2), "Viernes Santo"),
        ChileHoliday(easter - timedelta(days=1), "Sabado Santo"),
        ChileHoliday(date(year, 5, 1), "Dia del Trabajador", "irrenunciable"),
        ChileHoliday(date(year, 5, 21), "Glorias Navales"),
        ChileHoliday(date(year, 6, 21), "Dia Nacional de los Pueblos Indigenas"),
        ChileHoliday(date(year, 7, 16), "Virgen del Carmen"),
        ChileHoliday(date(year, 8, 15), "Asuncion de la Virgen"),
        ChileHoliday(date(year, 9, 18), "Independencia Nacional", "irrenunciable"),
        ChileHoliday(date(year, 9, 19), "Glorias del Ejercito", "irrenunciable"),
        ChileHoliday(date(year, 10, 12), "Encuentro de Dos Mundos"),
        ChileHoliday(date(year, 10, 31), "Dia de las Iglesias Evangelicas"),
        ChileHoliday(date(year, 11, 1), "Todos los Santos"),
        ChileHoliday(date(year, 12, 8), "Inmaculada Concepcion"),
        ChileHoliday(date(year, 12, 25), "Navidad", "irrenunciable"),
    ]
