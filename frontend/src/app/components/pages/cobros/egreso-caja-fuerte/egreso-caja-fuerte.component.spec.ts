import { ComponentFixture, TestBed } from '@angular/core/testing';
import { EgresoCajaFuerteComponent } from './egreso-caja-fuerte.component';

describe('EgresoCajaFuerteComponent', () => {
  let component: EgresoCajaFuerteComponent;
  let fixture: ComponentFixture<EgresoCajaFuerteComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [EgresoCajaFuerteComponent],
    }).compileComponents();

    fixture = TestBed.createComponent(EgresoCajaFuerteComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
